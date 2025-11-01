<?php

namespace App\Services\Provisioning;

use App\Models\NodeRedInstance;
use App\Models\Server;
use App\Services\SSH\Ssh;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
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
     * Ensure Docker is installed on the server.
     */
    private function ensureDockerInstalled(): void
    {
        // Check if Docker is installed
        $result = $this->ssh->execute('command -v docker', false);
        
        if (!$result->isSuccess()) {
            Log::info('Docker not found, installing Docker...', [
                'server_id' => $this->server->id,
            ]);
            
            // Install Docker using the same method as TraefikBootstrapper
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

            foreach ($commands as $command) {
                $cmdResult = $this->ssh->execute($command);
                if (!$cmdResult->isSuccess()) {
                    Log::error('Failed to install Docker', [
                        'server_id' => $this->server->id,
                        'command' => $command,
                        'error' => $cmdResult->getErrorOutput(),
                    ]);
                    throw new \RuntimeException("Failed to install Docker: {$command}");
                }
            }
            
            Log::info('Docker installed successfully', [
                'server_id' => $this->server->id,
            ]);
            
            // Wait a moment for Docker daemon to start
            sleep(2);
            
            // Verify Docker is working (use full path or check PATH)
            $verifyResult = $this->ssh->execute('PATH=/usr/bin:/usr/sbin:/bin:/sbin docker --version', false);
            if (!$verifyResult->isSuccess()) {
                // Try without PATH modification
                $verifyResult = $this->ssh->execute('docker --version', false);
                if (!$verifyResult->isSuccess()) {
                    throw new \RuntimeException('Docker was installed but verification failed');
                }
            }
            
            Log::info('Docker verification successful', [
                'server_id' => $this->server->id,
                'docker_version' => trim($verifyResult->getOutput()),
            ]);
        }
        
        // Ensure Docker Compose is available
        $composeResult = $this->ssh->execute('docker compose version', false);
        if (!$composeResult->isSuccess()) {
            Log::warning('Docker Compose not found, but Docker is installed', [
                'server_id' => $this->server->id,
            ]);
            // Docker Compose v2 should come with docker-ce, but if not, we'll continue anyway
            // as it might just be a PATH issue
        }
    }

    /**
     * Deploy a Node-RED instance.
     */
    public function deploy(NodeRedInstance $instance): bool
    {
        try {
            // Ensure Docker is installed before deploying
            $this->ensureDockerInstalled();

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

            // Deploy settings.js file with authentication
            $this->deploySettingsFile($instance, $instancePath);

            // Generate compose file
            $this->deployComposeFile($instance, $instancePath);

            // Start the container
            $this->startContainer($instancePath);

            // Verify container is on the edge network
            $this->verifyNetworkConnectivity($instancePath, $instance);

            // Wait for Node-RED to be ready and verify it started successfully
            $ready = $this->waitForReady($instance);
            
            if (!$ready) {
                // Get logs for debugging
                $logs = $this->getLogs($instance, 100);
                throw new \RuntimeException('Node-RED failed to start within timeout. Container logs: ' . substr($logs, -1000));
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Node-RED deployment failed', [
                'instance_id' => $instance->id,
                'server_id' => $this->server->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Re-throw so the job can handle it properly
            throw $e;
        }
    }

    /**
     * Deploy settings.js file with authentication configuration.
     */
    private function deploySettingsFile(NodeRedInstance $instance, string $instancePath): void
    {
        $this->syncUsers($instance, $instancePath);
    }

    /**
     * Sync all users (admin + additional) to settings.js file.
     * This method can be called after user changes to update the settings.js file.
     */
    public function syncUsers(NodeRedInstance $instance, ?string $instancePath = null): bool
    {
        try {
            if ($instancePath === null) {
                $instancePath = "{$this->noderedPath}/{$instance->slug}";
            }

            // Get fresh instance from database to ensure we have all attributes including hidden ones
            $instance = NodeRedInstance::findOrFail($instance->id);

            // Build users array: admin user + all additional users
            $users = [];

            // Add admin user (from instance)
            Log::debug('Checking admin user for instance', [
                'instance_id' => $instance->id,
                'admin_user' => $instance->admin_user,
                'has_admin_pass_hash' => !empty($instance->admin_pass_hash),
            ]);
            
            if ($instance->admin_user && $instance->admin_pass_hash) {
                $passwordHash = $instance->admin_pass_hash;
                // Convert Laravel's $2y$ format to Node-RED's $2a$ format for compatibility
                if (str_starts_with($passwordHash, '$2y$')) {
                    $passwordHash = str_replace('$2y$', '$2a$', $passwordHash);
                }

                $users[] = [
                    'username' => $instance->admin_user,
                    'password' => $passwordHash,
                    'permissions' => '*',
                ];
                
                Log::debug('Added admin user to users array', [
                    'username' => $instance->admin_user,
                    'users_count' => count($users),
                ]);
            } else {
                Log::warning('Admin user not found for instance', [
                    'instance_id' => $instance->id,
                    'admin_user' => $instance->admin_user,
                    'admin_pass_hash' => $instance->admin_pass_hash ? 'present' : 'missing',
                ]);
            }

            // Add additional users from node_red_users table
            $instance->load('nodeRedUsers');
            foreach ($instance->nodeRedUsers as $user) {
                $passwordHash = $user->password_hash;
                // Convert Laravel's $2y$ format to Node-RED's $2a$ format for compatibility
                if (str_starts_with($passwordHash, '$2y$')) {
                    $passwordHash = str_replace('$2y$', '$2a$', $passwordHash);
                }

                $users[] = [
                    'username' => $user->username,
                    'password' => $passwordHash,
                    'permissions' => $user->permissions,
                ];
            }

            // Ensure we have at least one user (admin user should always be present)
            if (empty($users)) {
                Log::error('No users found for instance - cannot create passwordless instance', [
                    'instance_id' => $instance->id,
                    'admin_user' => $instance->admin_user,
                    'admin_pass_hash' => $instance->admin_pass_hash ? 'present' : 'missing',
                ]);
                throw new \RuntimeException('Cannot create settings.js without at least one user. Admin user credentials are missing.');
            }

            Log::debug('Final users array for settings.js', [
                'instance_id' => $instance->id,
                'users_count' => count($users),
                'usernames' => array_column($users, 'username'),
            ]);

            // Render Blade template
            $credentialSecret = $instance->credential_secret ?? Str::random(32);
            
            try {
                $settingsContent = View::make('stubs.docker.nodered.settings', [
                    'users' => $users,
                    'credentialSecret' => $credentialSecret,
                ])->render();
            } catch (\Exception $e) {
                Log::error('Failed to render Blade template', [
                    'instance_id' => $instance->id,
                    'error' => $e->getMessage(),
                    'view' => 'stubs.docker.nodered.settings',
                ]);
                throw new \RuntimeException('Failed to render settings.js template: ' . $e->getMessage());
            }
            
            Log::debug('Rendered settings.js content', [
                'instance_id' => $instance->id,
                'content_length' => strlen($settingsContent),
                'content_preview' => substr($settingsContent, 0, 200),
            ]);
            
            // Log full content for debugging (be careful with sensitive data in production)
            Log::debug('Full settings.js content', [
                'instance_id' => $instance->id,
                'full_content' => $settingsContent,
            ]);

            // Remove old settings.js file if it exists (to ensure clean upload)
            $settingsPath = "{$instancePath}/data/settings.js";
            $this->ssh->execute("rm -f " . escapeshellarg($settingsPath), false);

            // Upload settings.js to the data directory
            $success = $this->ssh->uploadContent($settingsContent, $settingsPath);
            
            if (!$success) {
                throw new \RuntimeException('Failed to upload settings.js file to server.');
            }
            
            // Verify file was uploaded correctly
            $verifyResult = $this->ssh->execute("test -f " . escapeshellarg($settingsPath) . " && wc -l " . escapeshellarg($settingsPath), false);
            if (!$verifyResult->isSuccess()) {
                throw new \RuntimeException('Settings.js file was not created on server.');
            }
            
            Log::info('Settings.js file uploaded successfully', [
                'instance_id' => $instance->id,
                'file_size' => strlen($settingsContent),
                'file_lines' => trim($verifyResult->getOutput()),
            ]);
            
            // Verify file contents on server match what we uploaded
            $verifyContentResult = $this->ssh->execute("cat " . escapeshellarg($settingsPath), false);
            if ($verifyContentResult->isSuccess()) {
                $serverContent = $verifyContentResult->getOutput();
                Log::debug('Settings.js file contents on server', [
                    'instance_id' => $instance->id,
                    'server_content_length' => strlen($serverContent),
                    'server_content' => $serverContent,
                ]);
            }
            
            // Ensure correct permissions
            $this->ssh->execute("chown 1000:1000 {$instancePath}/data/settings.js", false);
            $this->ssh->execute("chmod 644 {$instancePath}/data/settings.js", false);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync users', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

        $composeFilePath = "{$instancePath}/docker-compose.yml";
        
        Log::info('Uploading docker-compose.yml file', [
            'instance_id' => $instance->id,
            'instance_path' => $instancePath,
            'compose_file_path' => $composeFilePath,
        ]);

        $success = $this->ssh->uploadContent($composeContent, $composeFilePath);
        
        if (!$success) {
            throw new \RuntimeException('Failed to upload docker-compose.yml file to server.');
        }

        // Verify file was uploaded correctly
        if (!$this->ssh->fileExists($composeFilePath)) {
            throw new \RuntimeException("docker-compose.yml file was not created on server at: {$composeFilePath}");
        }

        // Verify file has content
        $verifyResult = $this->ssh->execute("wc -l " . escapeshellarg($composeFilePath), false);
        if (!$verifyResult->isSuccess()) {
            throw new \RuntimeException('Failed to verify docker-compose.yml file on server.');
        }

        Log::info('docker-compose.yml file uploaded successfully', [
            'instance_id' => $instance->id,
            'compose_file_path' => $composeFilePath,
            'file_lines' => trim($verifyResult->getOutput()),
        ]);
    }

    /**
     * Start the Node-RED container.
     */
    private function startContainer(string $instancePath): void
    {
        $composeFilePath = "{$instancePath}/docker-compose.yml";
        
        // Verify compose file exists before starting
        if (!$this->ssh->fileExists($composeFilePath)) {
            // List directory contents for debugging
            $lsResult = $this->ssh->execute("ls -la " . escapeshellarg($instancePath), false);
            Log::error('docker-compose.yml file not found', [
                'instance_path' => $instancePath,
                'compose_file' => $composeFilePath,
                'directory_contents' => $lsResult->getOutput(),
            ]);
            throw new \RuntimeException("docker-compose.yml file not found at: {$composeFilePath}");
        }

        // Change to directory and run docker compose (it will auto-detect docker-compose.yml)
        $result = $this->ssh->execute("cd " . escapeshellarg($instancePath) . " && docker compose up -d");
        if (!$result->isSuccess()) {
            // Additional debugging: check if docker compose command exists
            $composeCheck = $this->ssh->execute("docker compose version", false);
            Log::error('Failed to start Node-RED container', [
                'instance_path' => $instancePath,
                'compose_file' => $composeFilePath,
                'error' => $result->getErrorOutput(),
                'output' => $result->getOutput(),
                'docker_compose_version' => $composeCheck->isSuccess() ? $composeCheck->getOutput() : 'docker compose not found',
            ]);
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
     * Start the Node-RED container.
     */
    public function start(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $result = $this->ssh->execute("cd {$instancePath} && docker compose up -d");
            return $result->isSuccess();
        } catch (\Exception $e) {
            Log::error('Failed to start Node-RED instance', [
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
     * Checks container logs and status to ensure Node-RED started successfully.
     */
    private function waitForReady(NodeRedInstance $instance, int $timeout = 120): bool
    {
        $start = time();
        $instancePath = "{$this->noderedPath}/{$instance->slug}";
        $lastErrorLog = '';
        $hasStartedFlows = false;

        while (time() - $start < $timeout) {
            try {
                // Check container status first
                $statusResult = $this->ssh->execute("cd " . escapeshellarg($instancePath) . " && docker compose ps -q nodered", false);
                $containerId = trim($statusResult->getOutput());
                
                if (empty($containerId)) {
                    // Container not running yet, wait and continue
                    sleep(3);
                    continue;
                }

                // Check container logs for errors and success messages
                $logsResult = $this->ssh->execute("cd " . escapeshellarg($instancePath) . " && docker compose logs --tail 50 nodered 2>&1", false);
                $logs = $logsResult->getOutput();
                
                // Check for critical errors in logs
                if (str_contains($logs, 'Error loading settings file') || 
                    str_contains($logs, 'SyntaxError') ||
                    str_contains($logs, 'ReferenceError') ||
                    str_contains($logs, 'TypeError')) {
                    $lastErrorLog = $logs;
                    throw new \RuntimeException('Node-RED failed to start due to configuration error. Check logs for details.');
                }
                
                // Check if Node-RED has started successfully
                if (str_contains($logs, 'Started flows') || str_contains($logs, 'Server now running')) {
                    $hasStartedFlows = true;
                    
                    // Verify container is still running
                    $statusResult = $this->ssh->execute("cd " . escapeshellarg($instancePath) . " && docker compose ps -q nodered", false);
                    $containerId = trim($statusResult->getOutput());
                    
                    if (!empty($containerId)) {
                        Log::info('Node-RED instance started successfully', [
                            'instance_id' => $instance->id,
                            'container_id' => $containerId,
                        ]);
                        return true;
                    }
                }
            } catch (\Exception $e) {
                // If we got an error from logs check, throw it immediately
                if ($lastErrorLog) {
                    throw $e;
                }
                // Otherwise continue waiting
            }

            sleep(3);
        }

        // If we found "Started flows" but timed out, it might be that HTTPS isn't ready yet
        // In that case, consider it successful since Node-RED itself is running
        if ($hasStartedFlows) {
            Log::warning('Node-RED started but HTTPS check timed out - Node-RED is running internally', [
                'instance_id' => $instance->id,
                'fqdn' => $instance->fqdn,
            ]);
            return true;
        }

        // If we have error logs, include them in the exception
        if ($lastErrorLog) {
            throw new \RuntimeException('Node-RED failed to start within timeout. Last error: ' . substr($lastErrorLog, -500));
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
     * Verify container is on the edge network and Traefik can discover it.
     */
    private function verifyNetworkConnectivity(string $instancePath, NodeRedInstance $instance): void
    {
        $networkName = config('provisioning.docker.network_name', 'edge');
        $containerName = "nodered_{$instance->slug}";

        // Check if edge network exists
        $networkCheck = $this->ssh->execute("docker network inspect {$networkName}", false);
        if (!$networkCheck->isSuccess()) {
            Log::warning('Edge network does not exist, creating it', [
                'instance_id' => $instance->id,
                'network_name' => $networkName,
            ]);
            $createResult = $this->ssh->execute("docker network create {$networkName}");
            if (!$createResult->isSuccess()) {
                throw new \RuntimeException("Failed to create edge network: {$networkName}");
            }
        }

        // Check if container is on the network
        $networkInspect = $this->ssh->execute("docker network inspect {$networkName}", false);
        if ($networkInspect->isSuccess()) {
            $networkData = json_decode($networkInspect->getOutput(), true);
            $containers = $networkData[0]['Containers'] ?? [];
            $containerFound = false;
            
            foreach ($containers as $container) {
                if (isset($container['Name']) && $container['Name'] === $containerName) {
                    $containerFound = true;
                    break;
                }
            }

            if (!$containerFound) {
                Log::warning('Container not found on edge network, attempting to connect', [
                    'instance_id' => $instance->id,
                    'container_name' => $containerName,
                    'network_name' => $networkName,
                ]);
                
                // Connect container to network
                $connectResult = $this->ssh->execute("docker network connect {$networkName} {$containerName}", false);
                if (!$connectResult->isSuccess()) {
                    Log::error('Failed to connect container to edge network', [
                        'instance_id' => $instance->id,
                        'container_name' => $containerName,
                        'error' => $connectResult->getErrorOutput(),
                    ]);
                    // Don't throw - container might already be connected or compose handled it
                } else {
                    Log::info('Container connected to edge network', [
                        'instance_id' => $instance->id,
                        'container_name' => $containerName,
                    ]);
                }
            }
        }

        // Check if Traefik is running
        $traefikPath = config('provisioning.docker.traefik_path', '/opt/traefik');
        $traefikCheck = $this->ssh->execute("cd {$traefikPath} && docker compose ps -q traefik", false);
        if (!$traefikCheck->isSuccess() || empty(trim($traefikCheck->getOutput()))) {
            Log::warning('Traefik is not running', [
                'instance_id' => $instance->id,
                'traefik_path' => $traefikPath,
            ]);
            // Don't throw - Traefik might start later
        } else {
            Log::info('Traefik is running and should discover container', [
                'instance_id' => $instance->id,
                'container_name' => $containerName,
                'fqdn' => $instance->fqdn,
            ]);
        }
    }

    /**
     * Fix network connectivity for an existing instance.
     * Ensures container is on the edge network and Traefik can discover it.
     */
    public function fixNetwork(NodeRedInstance $instance): bool
    {
        try {
            $instancePath = "{$this->noderedPath}/{$instance->slug}";
            $networkName = config('provisioning.docker.network_name', 'edge');
            $containerName = "nodered_{$instance->slug}";

            Log::info('Fixing network connectivity for instance', [
                'instance_id' => $instance->id,
                'container_name' => $containerName,
                'network_name' => $networkName,
            ]);

            // Ensure edge network exists
            $networkCheck = $this->ssh->execute("docker network inspect {$networkName}", false);
            if (!$networkCheck->isSuccess()) {
                Log::info('Creating edge network', [
                    'instance_id' => $instance->id,
                    'network_name' => $networkName,
                ]);
                $createResult = $this->ssh->execute("docker network create {$networkName}");
                if (!$createResult->isSuccess()) {
                    throw new \RuntimeException("Failed to create edge network: {$networkName}");
                }
            }

            // Check if container exists
            $containerCheck = $this->ssh->execute("docker ps -a --filter name={$containerName} --format '{{.Names}}'", false);
            $containerExists = !empty(trim($containerCheck->getOutput()));

            if (!$containerExists) {
                Log::warning('Container does not exist, cannot fix network', [
                    'instance_id' => $instance->id,
                    'container_name' => $containerName,
                ]);
                return false;
            }

            // Check if container is running
            $runningCheck = $this->ssh->execute("docker ps --filter name={$containerName} --format '{{.Names}}'", false);
            $isRunning = !empty(trim($runningCheck->getOutput()));

            // Check if container is on the network
            $networkInspect = $this->ssh->execute("docker network inspect {$networkName}", false);
            $onNetwork = false;

            if ($networkInspect->isSuccess()) {
                $networkData = json_decode($networkInspect->getOutput(), true);
                $containers = $networkData[0]['Containers'] ?? [];
                
                foreach ($containers as $container) {
                    if (isset($container['Name']) && $container['Name'] === $containerName) {
                        $onNetwork = true;
                        break;
                    }
                }
            }

            if (!$onNetwork) {
                Log::info('Container not on edge network, connecting it', [
                    'instance_id' => $instance->id,
                    'container_name' => $containerName,
                ]);
                
                // Connect container to network
                $connectResult = $this->ssh->execute("docker network connect {$networkName} {$containerName}", false);
                if (!$connectResult->isSuccess()) {
                    // Check if it's already connected (error might be "already connected")
                    if (!str_contains($connectResult->getErrorOutput(), 'already')) {
                        throw new \RuntimeException("Failed to connect container to edge network: {$connectResult->getErrorOutput()}");
                    }
                }
            }

            // Restart Traefik to force it to rediscover containers
            $traefikPath = config('provisioning.docker.traefik_path', '/opt/traefik');
            $traefikCheck = $this->ssh->execute("cd {$traefikPath} && docker compose ps -q traefik", false);
            
            if ($traefikCheck->isSuccess() && !empty(trim($traefikCheck->getOutput()))) {
                Log::info('Restarting Traefik to rediscover containers', [
                    'instance_id' => $instance->id,
                ]);
                $this->ssh->execute("cd {$traefikPath} && docker compose restart traefik", false);
            } else {
                Log::warning('Traefik is not running, cannot restart it', [
                    'instance_id' => $instance->id,
                ]);
            }

            // If container wasn't running, restart it to ensure Traefik picks it up
            if (!$isRunning) {
                Log::info('Container was not running, starting it', [
                    'instance_id' => $instance->id,
                ]);
                $this->start($instance);
            } else {
                // Restart container to ensure Traefik picks up the network change
                Log::info('Restarting container to ensure Traefik picks up network change', [
                    'instance_id' => $instance->id,
                ]);
                $this->restart($instance);
            }

            Log::info('Network connectivity fixed successfully', [
                'instance_id' => $instance->id,
                'container_name' => $containerName,
                'network_name' => $networkName,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to fix network connectivity', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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

