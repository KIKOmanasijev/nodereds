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
            // Install Docker if not present
            if (!$this->isDockerInstalled()) {
                $this->installDocker();
            }

            // Install Docker Compose if not present
            if (!$this->isDockerComposeInstalled()) {
                $this->installDockerCompose();
            }

            // Create Docker network
            $this->createDockerNetwork();

            // Setup Traefik directory
            $this->setupTraefikDirectory();

            // Deploy Traefik compose file
            $this->deployTraefikCompose();

            // Start Traefik
            $this->startTraefik();

            return true;
        } catch (\Exception $e) {
            Log::error('Traefik bootstrap failed', [
                'server_host' => $this->ssh->getHost(),
                'error' => $e->getMessage(),
            ]);
            return false;
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
        $commands = [
            'apt-get update',
            'apt-get install -y apt-transport-https ca-certificates curl gnupg lsb-release',
            'install -m 0755 -d /etc/apt/keyrings',
            'curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg',
            'chmod a+r /etc/apt/keyrings/docker.gpg',
            'echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null',
            'apt-get update',
            'apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin',
            'systemctl enable docker',
            'systemctl start docker',
        ];

        foreach ($commands as $command) {
            $result = $this->ssh->execute($command);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Failed to install Docker: {$command}");
            }
        }
    }

    /**
     * Check if Docker Compose is installed.
     */
    private function isDockerComposeInstalled(): bool
    {
        $result = $this->ssh->execute('docker compose version', false);
        return $result->isSuccess();
    }

    /**
     * Install Docker Compose (standalone if needed).
     */
    private function installDockerCompose(): void
    {
        // Docker Compose v2 comes with Docker, so if we're here, something is wrong
        // But we can install standalone version as fallback
        $commands = [
            'curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose',
            'chmod +x /usr/local/bin/docker-compose',
        ];

        foreach ($commands as $command) {
            $result = $this->ssh->execute($command);
            if (!$result->isSuccess()) {
                throw new \RuntimeException("Failed to install Docker Compose: {$command}");
            }
        }
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

