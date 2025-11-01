<?php

namespace App\Console\Commands;

use App\Services\Hetzner\HetznerClient;
use Illuminate\Console\Command;

class AddSshKeyToHetznerCommand extends Command
{
    protected $signature = 'ssh:add-to-hetzner';
    protected $description = 'Add the local SSH public key to Hetzner Cloud';

    public function handle(HetznerClient $hetznerClient): int
    {
        $keyPath = config('provisioning.ssh.private_key_path');
        $publicKeyPath = $keyPath . '.pub';

        if (!file_exists($publicKeyPath)) {
            $this->error("Public key not found at: {$publicKeyPath}");
            return Command::FAILURE;
        }

        $publicKey = trim(file_get_contents($publicKeyPath));
        $keyName = 'nodereds-provision-key';

        $this->info("Adding SSH key to Hetzner Cloud...");
        $this->info("Key name: {$keyName}");
        $this->info("Public key: " . substr($publicKey, 0, 50) . "...");

        try {
            // Check if key already exists
            $existingKeyId = $hetznerClient->getSshKeyIdByName($keyName);
            if ($existingKeyId) {
                $this->warn("SSH key '{$keyName}' already exists in Hetzner Cloud (ID: {$existingKeyId})");
                $this->info("You can update HETZNER_SSH_KEY_NAME={$keyName} in .env to use it");
                return Command::SUCCESS;
            }

            $key = $hetznerClient->createSshKey($keyName, $publicKey);

            $this->info("✓ SSH key added successfully!");
            $this->info("  Key ID: {$key['id']}");
            $this->info("  Key Name: {$key['name']}");
            $this->info("\nNext steps:");
            $this->info("1. Update .env: HETZNER_SSH_KEY_NAME={$keyName}");
            $this->warn("2. For existing server (91.98.232.217), manually add the key via Hetzner Console:");
            $this->line("   - Go to Hetzner Cloud Console");
            $this->line("   - Open server nr-server-20251101120703");
            $this->line("   - Click 'Console' tab");
            $this->line("   - Run these commands:");
            $this->line("     mkdir -p ~/.ssh");
            $this->line("     echo '{$publicKey}' >> ~/.ssh/authorized_keys");
            $this->line("     chmod 600 ~/.ssh/authorized_keys");
            $this->line("     chmod 700 ~/.ssh");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to add SSH key: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}

