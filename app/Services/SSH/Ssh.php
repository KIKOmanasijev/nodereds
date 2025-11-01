<?php

namespace App\Services\SSH;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class Ssh
{
    private string $host;
    private string $username;
    private string $privateKeyPath;
    private int $timeout;
    private int $port;

    public function __construct(string $host, ?string $username = null, ?string $privateKeyPath = null, ?int $timeout = null, int $port = 22)
    {
        $this->host = $host;
        $this->username = $username ?? config('provisioning.ssh.username', 'root');
        $this->privateKeyPath = $privateKeyPath ?? config('provisioning.ssh.private_key_path');
        $this->timeout = $timeout ?? config('provisioning.ssh.timeout', 30);
        $this->port = $port;

        if (empty($this->privateKeyPath)) {
            throw new \RuntimeException("SSH private key path is not configured. Please set SSH_PRIVATE_KEY_PATH in .env");
        }

        if (!file_exists($this->privateKeyPath)) {
            throw new \RuntimeException(
                "SSH private key not found at: {$this->privateKeyPath}\n" .
                "Generate a key pair with: ssh-keygen -t ed25519 -f {$this->privateKeyPath} -N ''\n" .
                "Then add public key to Hetzner: php artisan ssh:add-to-hetzner"
            );
        }

        // Check key permissions
        $keyPermissions = substr(sprintf('%o', fileperms($this->privateKeyPath)), -4);
        if ($keyPermissions !== '0600' && $keyPermissions !== '0400') {
            Log::warning('SSH key permissions may be insecure', [
                'path' => $this->privateKeyPath,
                'permissions' => $keyPermissions,
                'recommended' => '0600',
            ]);
        }
    }

    /**
     * Execute a command on the remote server.
     */
    public function execute(string $command, bool $throwOnError = true): SshResult
    {
        $sshCommand = $this->buildSshCommand($command);

        $process = Process::fromShellCommandline($sshCommand, null, null, null, $this->timeout);
        $process->run();

        $result = new SshResult(
            $process->getExitCode(),
            $process->getOutput(),
            $process->getErrorOutput()
        );

        if ($throwOnError && !$result->isSuccess()) {
            $errorMessage = $result->errorOutput;
            $diagnostics = '';
            
            // Provide helpful diagnostics for common errors
            if (str_contains($errorMessage, 'Permission denied')) {
                $publicKeyPath = $this->privateKeyPath . '.pub';
                $publicKey = file_exists($publicKeyPath) ? trim(file_get_contents($publicKeyPath)) : 'N/A';
                
                $diagnostics = "\n\nTroubleshooting:\n";
                $diagnostics .= "1. Verify SSH key exists: " . ($this->privateKeyPath) . "\n";
                $diagnostics .= "2. Add public key to server's authorized_keys:\n";
                $diagnostics .= "   ssh root@{$this->host}\n";
                $diagnostics .= "   mkdir -p ~/.ssh\n";
                $diagnostics .= "   echo '{$publicKey}' >> ~/.ssh/authorized_keys\n";
                $diagnostics .= "   chmod 600 ~/.ssh/authorized_keys\n";
                $diagnostics .= "   chmod 700 ~/.ssh\n";
                $diagnostics .= "3. Test connection: php artisan ssh:test\n";
            }
            
            Log::error('SSH command failed', [
                'host' => $this->host,
                'command' => $command,
                'exit_code' => $result->exitCode,
                'error' => $errorMessage,
            ]);
            
            throw new \RuntimeException("SSH command failed: {$command}\nError: {$errorMessage}{$diagnostics}");
        }

        return $result;
    }

    /**
     * Execute multiple commands sequentially.
     */
    public function executeMany(array $commands, bool $throwOnError = true): array
    {
        $results = [];
        foreach ($commands as $command) {
            $results[] = $this->execute($command, $throwOnError);
        }
        return $results;
    }

    /**
     * Upload a file to the remote server.
     */
    public function uploadFile(string $localPath, string $remotePath, int $mode = 0644): bool
    {
        if (!file_exists($localPath)) {
            throw new \RuntimeException("Local file not found: {$localPath}");
        }

        $scpCommand = sprintf(
            'scp -i %s -P %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o BatchMode=yes %s %s@%s:%s',
            escapeshellarg($this->privateKeyPath),
            $this->port,
            escapeshellarg($localPath),
            escapeshellarg($this->username),
            escapeshellarg($this->host),
            escapeshellarg($remotePath)
        );

        $process = Process::fromShellCommandline($scpCommand, null, null, null, $this->timeout);
        $process->run();

        if ($process->isSuccessful()) {
            // Set permissions if specified
            if ($mode !== null) {
                $this->execute("chmod " . decoct($mode) . " " . escapeshellarg($remotePath), false);
            }
            return true;
        }

        Log::error('SCP upload failed', [
            'host' => $this->host,
            'local_path' => $localPath,
            'remote_path' => $remotePath,
            'error' => $process->getErrorOutput(),
        ]);

        return false;
    }

    /**
     * Upload string content as a file.
     */
    public function uploadContent(string $content, string $remotePath, int $mode = 0644): bool
    {
        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'ssh_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        try {
            file_put_contents($tempFile, $content);
            return $this->uploadFile($tempFile, $remotePath, $mode);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Create a directory on the remote server.
     */
    public function createDirectory(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        $flags = $recursive ? '-p' : '';
        $result = $this->execute("mkdir {$flags} -m " . decoct($mode) . " " . escapeshellarg($path), false);
        return $result->isSuccess();
    }

    /**
     * Check if a file exists on the remote server.
     */
    public function fileExists(string $path): bool
    {
        $result = $this->execute("test -f " . escapeshellarg($path), false);
        return $result->isSuccess();
    }

    /**
     * Check if a directory exists on the remote server.
     */
    public function directoryExists(string $path): bool
    {
        $result = $this->execute("test -d " . escapeshellarg($path), false);
        return $result->isSuccess();
    }

    /**
     * Build SSH command string.
     */
    private function buildSshCommand(string $command): string
    {
        return sprintf(
            'ssh -i %s -p %d -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=%d -o PasswordAuthentication=no -o BatchMode=yes %s@%s %s',
            escapeshellarg($this->privateKeyPath),
            $this->port,
            min(10, $this->timeout),
            escapeshellarg($this->username),
            escapeshellarg($this->host),
            escapeshellarg($command)
        );
    }

    /**
     * Test SSH connection.
     */
    public function testConnection(): bool
    {
        try {
            $result = $this->execute('echo "ok"', false);
            return $result->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the host address.
     */
    public function getHost(): string
    {
        return $this->host;
    }
}

