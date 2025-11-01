<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SSH\Ssh;
use Illuminate\Console\Command;

class TestSshConnectionCommand extends Command
{
    protected $signature = 'ssh:test {server_id?}';
    protected $description = 'Test SSH connection to a server';

    public function handle(): int
    {
        $serverId = $this->argument('server_id');

        if ($serverId) {
            $server = Server::findOrFail($serverId);
            $this->testServer($server);
        } else {
            $servers = Server::whereNotNull('provider_id')->get();
            
            if ($servers->isEmpty()) {
                $this->error('No servers found with provider_id set.');
                return Command::FAILURE;
            }

            $this->info("Testing SSH connectivity to {$servers->count()} server(s)...\n");

            foreach ($servers as $server) {
                $this->testServer($server);
                $this->newLine();
            }
        }

        return Command::SUCCESS;
    }

    private function testServer(Server $server): void
    {
        $this->info("Testing server: {$server->name} ({$server->public_ip})");

        // Check SSH key exists
        $keyPath = config('provisioning.ssh.private_key_path');
        if (!file_exists($keyPath)) {
            $this->error("  ✗ SSH private key not found at: {$keyPath}");
            $this->warn("     Generate a key pair: ssh-keygen -t ed25519 -f storage/keys/provision -N ''");
            $this->warn("     Then add public key to Hetzner: php artisan ssh:add-to-hetzner");
            return;
        }

        $keyPermissions = substr(sprintf('%o', fileperms($keyPath)), -4);
        if ($keyPermissions !== '0600' && $keyPermissions !== '0400') {
            $this->warn("  ⚠ SSH key permissions are {$keyPermissions} (should be 0600 or 0400)");
            $this->warn("     Fix with: chmod 600 {$keyPath}");
        } else {
            $this->info("  ✓ SSH key found at: {$keyPath}");
        }

        // Check public key exists
        $publicKeyPath = $keyPath . '.pub';
        if (!file_exists($publicKeyPath)) {
            $this->error("  ✗ SSH public key not found at: {$publicKeyPath}");
            return;
        }

        $publicKey = trim(file_get_contents($publicKeyPath));
        $this->info("  ✓ Public key: " . substr($publicKey, 0, 50) . "...");

        // Test SSH connection
        try {
            $ssh = new Ssh($server->public_ip);
            $result = $ssh->testConnection();

            if ($result) {
                $this->info("  ✓ SSH connection successful!");
                
                // Get server info
                $info = $ssh->execute('uname -a', false);
                if ($info->isSuccess()) {
                    $this->info("  ✓ Server info: " . trim($info->output));
                }
            } else {
                $this->error("  ✗ SSH connection failed!");
                $this->warn("     Possible causes:");
                $this->warn("     1. Public key not added to server's authorized_keys");
                $this->warn("     2. Wrong SSH key is being used");
                $this->warn("     3. Server firewall blocking SSH");
                $this->newLine();
                $this->warn("     To add the key manually:");
                $this->line("       ssh root@{$server->public_ip}");
                $this->line("       mkdir -p ~/.ssh");
                $this->line("       echo '{$publicKey}' >> ~/.ssh/authorized_keys");
                $this->line("       chmod 600 ~/.ssh/authorized_keys");
                $this->line("       chmod 700 ~/.ssh");
            }
        } catch (\Exception $e) {
            $this->error("  ✗ SSH connection failed: {$e->getMessage()}");
            $this->warn("     Check that:");
            $this->warn("     1. SSH key exists at: {$keyPath}");
            $this->warn("     2. Public key is added to Hetzner Cloud");
            $this->warn("     3. Public key is added to server's ~/.ssh/authorized_keys");
        }
    }
}

