<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use App\Services\SSH\Ssh;
use Illuminate\Support\Facades\Log;

class TraefikBootstrapper
{
    private Ssh $ssh;
    private string $traefikPath;

    public function __construct(Server $server)
    {
        $this->ssh = new Ssh($server->public_ip);
        $this->traefikPath = config('provisioning.docker.traefik_path', '/opt/traefik');
    }

    /**
     * Bootstrap Docker and Traefik on the server.
     */
    public function bootstrap(): bool
    {
        try {
            Log::info('Starting server bootstrap', [
                'server_host' => $this->ssh->getHost(),
            ]);

            // Ensure SSH connection works before proceeding
            $this->ensureSshConnection();

            // Setup SSH key first to ensure we can connect
            $this->setupSshKey();

            // Install Docker if not present
            if (!$this->isDockerInstalled()) {
                Log::info('Docker not found, installing...', [
                    'server_host' => $this->ssh->getHost(),
                ]);
                $this->installDocker();
            } else {
                Log::info('Docker already installed, skipping installation', [
                    'server_host' => $this->ssh->getHost(),
                ]);
            }

            // Install Docker Compose if not present
            if (!$this->isDockerComposeInstalled()) {
                Log::info('Docker Compose not found, installing...', [
                    'server_host' => $this->ssh->getHost(),
                ]);
                $this->installDockerCompose();
            } else {
                Log::info('Docker Compose already installed, skipping installation', [
                    'server_host' => $this->ssh->getHost(),
                ]);
            }

            // Create Docker network
            $this->createDockerNetwork();

            // Setup Traefik directory
            $this->setupTraefikDirectory();

            // Deploy Traefik compose file
            $this->deployTraefikCompose();

            // Start Traefik
            $this->startTraefik();

            Log::info('Server bootstrap completed successfully', [
                'server_host' => $this->ssh->getHost(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Traefik bootstrap failed', [
                'server_host' => $this->ssh->getHost(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Ensure SSH connection is working before proceeding.
     * Retries connection with exponential backoff.
     */
    private function ensureSshConnection(): void
    {
        $maxAttempts = 10;
        $baseDelay = 5; // Start with 5 seconds
        
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $testResult = $this->ssh->testConnection();
                
                if ($testResult) {
                    Log::info('SSH connection successful', [
                        'server_host' => $this->ssh->getHost(),
                        'attempt' => $attempt,
                    ]);
                    return;
                }
            } catch (\Exception $e) {
                Log::debug('SSH connection attempt failed', [
                    'server_host' => $this->ssh->getHost(),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);
            }
            
            if ($attempt < $maxAttempts) {
                $delay = $baseDelay * $attempt; // Exponential backoff: 5s, 10s, 15s, etc.
                Log::info('SSH connection not ready, waiting before retry', [
                    'server_host' => $this->ssh->getHost(),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'delay_seconds' => $delay,
                ]);
                sleep($delay);
            }
        }
        
        // If we get here, all attempts failed
        throw new \RuntimeException(
            "SSH connection failed after {$maxAttempts} attempts. " .
            "Server: {$this->ssh->getHost()}. " .
            "Please verify: " .
            "1. SSH key is added to Hetzner Cloud (HETZNER_SSH_KEY_NAME matches an existing key). " .
            "2. Server is fully booted and SSH service is running. " .
            "3. Server firewall allows SSH connections."
        );
    }

    /**
     * Setup SSH key on the server by adding it to authorized_keys.
     * This ensures we can connect via SSH without manual intervention.
     * Note: This only works if SSH is already possible (e.g., via Hetzner's cloud-init or password).
     * If Hetzner added the SSH key during server creation, this will be a no-op.
     */
    private function setupSshKey(): void
    {
        $privateKeyPath = config('provisioning.ssh.private_key_path');
        $publicKeyPath = $privateKeyPath . '.pub';

        if (!file_exists($publicKeyPath)) {
            Log::warning('SSH public key not found, skipping SSH key setup', [
                'server_host' => $this->ssh->getHost(),
                'public_key_path' => $publicKeyPath,
            ]);
            return;
        }

        $publicKey = trim(file_get_contents($publicKeyPath));
        if (empty($publicKey)) {
            Log::warning('SSH public key is empty, skipping SSH key setup', [
                'server_host' => $this->ssh->getHost(),
            ]);
            return;
        }

        Log::info('Ensuring SSH key is set up on server', [
            'server_host' => $this->ssh->getHost(),
        ]);

        try {
            // Ensure .ssh directory exists
            $this->ssh->execute("mkdir -p ~/.ssh", false);
            $this->ssh->execute("chmod 700 ~/.ssh", false);

            // Check if key is already in authorized_keys
            $checkKey = $this->ssh->execute("grep -Fx '{$publicKey}' ~/.ssh/authorized_keys", false);
            
            if (!$checkKey->isSuccess()) {
                // Key not found, add it
                Log::info('Adding SSH public key to authorized_keys', [
                    'server_host' => $this->ssh->getHost(),
                ]);
                
                // Append key to authorized_keys
                $addKey = $this->ssh->execute("echo '{$publicKey}' >> ~/.ssh/authorized_keys", false);
                
                if ($addKey->isSuccess()) {
                    // Set proper permissions
                    $this->ssh->execute("chmod 600 ~/.ssh/authorized_keys", false);
                    
                    Log::info('SSH key added successfully', [
                        'server_host' => $this->ssh->getHost(),
                    ]);
                } else {
                    Log::warning('Failed to add SSH key (may already be there via Hetzner)', [
                        'server_host' => $this->ssh->getHost(),
                        'error' => $addKey->getErrorOutput(),
                    ]);
                }
            } else {
                Log::info('SSH key already exists in authorized_keys', [
                    'server_host' => $this->ssh->getHost(),
                ]);
            }
        } catch (\Exception $e) {
            // If SSH key setup fails, it might be because:
            // 1. Hetzner already added it (good!)
            // 2. We can't SSH yet (server still booting)
            // 3. Some other issue
            // Log warning but continue - if Hetzner added it, SSH will work anyway
            Log::warning('SSH key setup failed (may already be configured via Hetzner)', [
                'server_host' => $this->ssh->getHost(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if Docker is installed.
     */
    private function isDockerInstalled(): bool
    {
        $result = $this->ssh->execute('command -v docker', false);
        return $result->isSuccess();
    }

    /**
     * Install Docker.
     */
    private function installDocker(): void
    {
        Log::info('Installing Docker on server', [
            'server_host' => $this->ssh->getHost(),
        ]);

        $commands = [
            'apt-get update -qq',
            'apt-get install -y -qq apt-transport-https ca-certificates curl gnupg lsb-release',
            'install -m 0755 -d /etc/apt/keyrings',
            'curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg',
            'chmod a+r /etc/apt/keyrings/docker.gpg',
            'echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null',
            'apt-get update -qq',
            'apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin',
            'systemctl enable docker',
            'systemctl start docker',
        ];

        foreach ($commands as $index => $command) {
            Log::debug('Executing Docker installation command', [
                'server_host' => $this->ssh->getHost(),
                'step' => $index + 1,
                'total' => count($commands),
                'command' => $command,
            ]);

            $result = $this->ssh->execute($command);
            if (!$result->isSuccess()) {
                Log::error('Docker installation command failed', [
                    'server_host' => $this->ssh->getHost(),
                    'command' => $command,
                    'error' => $result->getErrorOutput(),
                    'output' => $result->getOutput(),
                ]);
                throw new \RuntimeException("Failed to install Docker: {$command}. Error: {$result->getErrorOutput()}");
            }
        }

        // Wait a moment for Docker daemon to start
        sleep(2);

        // Verify Docker is working
        $verifyResult = $this->ssh->execute('docker --version', false);
        if (!$verifyResult->isSuccess()) {
            Log::error('Docker installation verification failed', [
                'server_host' => $this->ssh->getHost(),
                'error' => $verifyResult->getErrorOutput(),
            ]);
            throw new \RuntimeException('Docker was installed but verification failed: ' . $verifyResult->getErrorOutput());
        }

        Log::info('Docker installed successfully', [
            'server_host' => $this->ssh->getHost(),
            'docker_version' => trim($verifyResult->getOutput()),
        ]);
    }

    /**
     * Check if Docker Compose is installed.
     */
    private function isDockerComposeInstalled(): bool
    {
        $result = $this->ssh->execute('docker compose version', false);
        if (!$result->isSuccess()) {
            Log::debug('Docker Compose not found, will attempt to use standalone version', [
                'server_host' => $this->ssh->getHost(),
            ]);
        }
        return $result->isSuccess();
    }

    /**
     * Install Docker Compose (standalone if needed).
     */
    private function installDockerCompose(): void
    {
        // Docker Compose v2 (docker compose) comes with docker-compose-plugin package
        // If docker compose is not available, try reinstalling the plugin
        Log::warning('Docker Compose not found after Docker installation, attempting to reinstall plugin', [
            'server_host' => $this->ssh->getHost(),
        ]);

        $commands = [
            'apt-get install -y -qq docker-compose-plugin --reinstall',
        ];

        foreach ($commands as $command) {
            $result = $this->ssh->execute($command);
            if (!$result->isSuccess()) {
                Log::error('Failed to reinstall Docker Compose plugin', [
                    'server_host' => $this->ssh->getHost(),
                    'command' => $command,
                    'error' => $result->getErrorOutput(),
                ]);
                throw new \RuntimeException("Failed to install Docker Compose plugin: {$command}. Error: {$result->getErrorOutput()}");
            }
        }

        // Wait a moment for plugin to be available
        sleep(2);

        // Verify docker compose is now available
        $verifyResult = $this->ssh->execute('docker compose version', false);
        if (!$verifyResult->isSuccess()) {
            Log::error('Docker Compose plugin installation verification failed', [
                'server_host' => $this->ssh->getHost(),
                'error' => $verifyResult->getErrorOutput(),
            ]);
            throw new \RuntimeException('Docker Compose plugin was installed but verification failed: ' . $verifyResult->getErrorOutput());
        }

        Log::info('Docker Compose plugin installed successfully', [
            'server_host' => $this->ssh->getHost(),
            'docker_compose_version' => trim($verifyResult->getOutput()),
        ]);
    }

    /**
     * Create Docker network.
     */
    private function createDockerNetwork(): void
    {
        $networkName = config('provisioning.docker.network_name', 'edge');
        $result = $this->ssh->execute("docker network inspect {$networkName}", false);
        
        if (!$result->isSuccess()) {
            $createResult = $this->ssh->execute("docker network create {$networkName}");
            if (!$createResult->isSuccess()) {
                throw new \RuntimeException("Failed to create Docker network: {$networkName}");
            }
        }
    }

    /**
     * Setup Traefik directory.
     */
    private function setupTraefikDirectory(): void
    {
        if (!$this->ssh->directoryExists($this->traefikPath)) {
            $this->ssh->createDirectory($this->traefikPath);
        }

        // Create acme.json with proper permissions
        $acmePath = "{$this->traefikPath}/acme.json";
        if (!$this->ssh->fileExists($acmePath)) {
            $this->ssh->execute("touch {$acmePath}");
            $this->ssh->execute("chmod 600 {$acmePath}");
        }
    }

    /**
     * Deploy Traefik compose file.
     */
    private function deployTraefikCompose(): void
    {
        $composeContent = file_get_contents(resource_path('stubs/docker/traefik/docker-compose.yml'));
        
        // Replace environment variables
        $cloudflareToken = config('provisioning.cloudflare.api_token');
        $cloudflareZoneId = config('provisioning.cloudflare.zone_id');
        $acmeEmail = config('app.email', 'admin@' . config('provisioning.dns.base_domain', 'nodereds.com'));

        $envContent = "CLOUDFLARE_DNS_API_TOKEN={$cloudflareToken}\n";
        $envContent .= "CLOUDFLARE_ZONE_ID={$cloudflareZoneId}\n";
        $envContent .= "ACME_EMAIL={$acmeEmail}\n";

        $this->ssh->uploadContent($composeContent, "{$this->traefikPath}/docker-compose.yml");
        $this->ssh->uploadContent($envContent, "{$this->traefikPath}/.env");
    }

    /**
     * Start Traefik.
     */
    private function startTraefik(): void
    {
        $result = $this->ssh->execute("cd {$this->traefikPath} && docker compose up -d");
        if (!$result->isSuccess()) {
            throw new \RuntimeException("Failed to start Traefik");
        }
    }

    /**
     * Check if Traefik is running.
     */
    public function isTraefikRunning(): bool
    {
        $result = $this->ssh->execute("cd {$this->traefikPath} && docker compose ps -q traefik", false);
        return $result->isSuccess() && !empty(trim($result->getOutput()));
    }
}

