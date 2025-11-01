<?php

namespace App\Services\Provisioning;

use App\Models\NodeRedInstance;
use App\Models\Server;
use App\Services\SSH\Ssh;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NodeRedDeployer
{
    private Ssh $ssh;
    private Server $server;
    private string $noderedPath;

    public function __construct(Server $server)
    {
        $this->server = $server;
        $this->ssh = new Ssh($server->public_ip);
        $this->noderedPath = config('provisioning.docker.nodered_path', '/opt/nodered');
    }

    /**
     * Deploy a Node-RED instance.
     */
    public function deploy(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";

            // Create instance directory
            $this->ssh->createDirectory($instancePath);

            // Create data directory with correct permissions for Node-RED (UID 1000)
            $dataPath = "{$instancePath}/data";
            $this->ssh->createDirectory($dataPath);
            
            // Set ownership to UID 1000 (node-red user inside container)
            // This ensures Node-RED can write to /data directory
            $this->ssh->execute("chown -R 1000:1000 {$dataPath}", false);
            $this->ssh->execute("chmod -R 755 {$dataPath}", false);

            // Generate compose file
            $this->deployComposeFile($instance, $instancePath);

            // Start the container
            $this->startContainer($instancePath);

            // Wait for Node-RED to be ready
            $this->waitForReady($instance);

            return true;
        } catch (\Exception $e) {
            Log::error('Node-RED deployment failed', [
                'instance_id' => $instance->id,
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Deploy Docker Compose file for the instance.
     */
    private function deployComposeFile(NodeRedInstance $instance, string $instancePath): void
    {
        $template = file_get_contents(resource_path('stubs/docker/nodered/docker-compose.yml'));

        // Calculate CPU limit (use 50% of a CPU as default for small instances)
        $cpuLimit = min(1.0, ($instance->plan->cpu_limit ?? 0.5));

        // Calculate Node.js memory (slightly less than container limit)
        $nodeMemory = max(128, (int) ($instance->memory_mb * 0.8));

        $replacements = [
            '{{SLUG}}' => $instance->slug,
            '{{MEMORY_LIMIT}}' => $instance->memory_mb . 'm',
            '{{CPU_LIMIT}}' => (string) $cpuLimit,
            '{{NODE_MEMORY}}' => (string) $nodeMemory,
            '{{CREDENTIAL_SECRET}}' => $instance->credential_secret ?? Str::random(32),
            '{{FQDN}}' => $instance->fqdn,
        ];

        $composeContent = str_replace(array_keys($replacements), array_values($replacements), $template);

        $this->ssh->uploadContent($composeContent, "{$instancePath}/docker-compose.yml");
    }

    /**
     * Start the Node-RED container.
     */
    private function startContainer(string $instancePath): void
    {
        $result = $this->ssh->execute("cd {$instancePath} && docker compose up -d");
        if (!$result->isSuccess()) {
            throw new \RuntimeException("Failed to start Node-RED container: {$result->getErrorOutput()}");
        }
    }

    /**
     * Stop the Node-RED container.
     */
    public function stop(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $result = $this->ssh->execute("cd {$instancePath} && docker compose stop", false);
            return $result->isSuccess();
        } catch (\Exception $e) {
            Log::error('Failed to stop Node-RED instance', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Restart the Node-RED container.
     */
    public function restart(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $result = $this->ssh->execute("cd {$instancePath} && docker compose restart");
            return $result->isSuccess();
        } catch (\Exception $e) {
            Log::error('Failed to restart Node-RED instance', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete the Node-RED instance.
     */
    public function delete(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            
            // Check if directory exists
            if (!$this->ssh->directoryExists($instancePath)) {
                Log::info('Instance directory does not exist, skipping deletion', [
                    'instance_id' => $instance->id,
                    'path' => $instancePath,
                ]);
                return true; // Already deleted, consider it successful
            }

            // Stop and remove container (ignore errors if container doesn't exist)
            $this->ssh->execute("cd {$instancePath} && docker compose down -v", false);

            // Remove directory
            $this->ssh->execute("rm -rf {$instancePath}", false);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete Node-RED instance', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Wait for Node-RED to be ready.
     */
    private function waitForReady(NodeRedInstance $instance, int $timeout = 60): bool
    {
        $start = time();
        $url = "https://{$instance->fqdn}";

        while (time() - $start < $timeout) {
            try {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 400) {
                    return true;
                }
            } catch (\Exception $e) {
                // Continue waiting
            }

            sleep(3);
        }

        return false;
    }

    /**
     * Get container logs.
     */
    public function getLogs(NodeRedInstance $instance, int $lines = 100): string
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $result = $this->ssh->execute("cd {$instancePath} && docker compose logs --tail={$lines} nodered", false);
            return $result->getOutput();
        } catch (\Exception $e) {
            Log::error('Failed to get Node-RED logs', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Check if container is running.
     */
    public function isRunning(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $result = $this->ssh->execute("cd {$instancePath} && docker compose ps -q nodered", false);
            $output = trim($result->getOutput());
            return !empty($output);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fix permissions on the data directory.
     */
    public function fixPermissions(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $dataPath = "{$instancePath}/data";
            
            // Set ownership to UID 1000 (node-red user inside container)
            $this->ssh->execute("chown -R 1000:1000 {$dataPath}", false);
            $this->ssh->execute("chmod -R 755 {$dataPath}", false);
            
            // Restart container to apply changes
            return $this->restart($instance);
        } catch (\Exception $e) {
            Log::error('Failed to fix permissions', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

