<?php

namespace App\Services\Hetzner;

use App\Models\Server;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HetznerClient
{
    private PendingRequest $client;

    private string $baseUrl = 'https://api.hetzner.cloud/v1';
    private string $token;

    public function __construct()
    {
        $token = config('provisioning.hetzner.token');
        if (empty($token)) {
            throw new \RuntimeException('Hetzner API token is not configured');
        }

        $this->token = $token;

        $this->client = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->baseUrl($this->baseUrl);
    }

    /**
     * Create a new server.
     */
    public function createServer(array $config): array
    {
        $defaultImage = config('provisioning.hetzner.default_image', 'ubuntu-24.04');
        $defaultRegion = config('provisioning.hetzner.default_region', 'nbg1');

        // Get SSH keys - either from config or fetch by name
        $sshKeys = $config['ssh_keys'] ?? [];
        $sshKeyName = config('provisioning.hetzner.ssh_key_name', 'provision-key');
        
        if (empty($sshKeys)) {
            Log::info('Looking up SSH key for server creation', [
                'ssh_key_name' => $sshKeyName,
            ]);
            
            $sshKeyId = $this->getSshKeyIdByName($sshKeyName);
            if ($sshKeyId) {
                $sshKeys = [(string) $sshKeyId];
                Log::info('SSH key found', [
                    'ssh_key_name' => $sshKeyName,
                    'ssh_key_id' => $sshKeyId,
                ]);
            } else {
                // Get available SSH keys for better error message
                $availableKeys = $this->getAllSshKeys();
                $availableKeyNames = array_column($availableKeys, 'name');
                
                Log::error('SSH key not found in Hetzner Cloud', [
                    'requested_key_name' => $sshKeyName,
                    'available_keys' => $availableKeyNames,
                ]);
                
                throw new \RuntimeException(
                    "SSH key '{$sshKeyName}' not found in Hetzner Cloud. " .
                    "Available keys: " . implode(', ', $availableKeyNames) . ". " .
                    "Please set HETZNER_SSH_KEY_NAME in your .env file to match one of these names, or add the SSH key '{$sshKeyName}' to Hetzner Cloud."
                );
            }
        } else {
            Log::info('Using SSH keys provided in config', [
                'ssh_keys' => $sshKeys,
            ]);
        }

        $payload = [
            'name' => $config['name'] ?? 'nr-server-' . now()->format('YmdHis'),
            'server_type' => $config['server_type'] ?? config('provisioning.hetzner.default_server_type', 'cx11'),
            'image' => $config['image'] ?? $defaultImage,
            'location' => $config['location'] ?? null,
            'datacenter' => $config['datacenter'] ?? null,
            'start_after_create' => true,
            // 'ssh_keys' => $sshKeys,
        ];

        // Only include networks if provided and not empty
        if (!empty($config['networks'])) {
            $payload['networks'] = $config['networks'];
        }

        // Only include labels if provided and not empty
        if (!empty($config['labels'])) {
            $payload['labels'] = $config['labels'];
        }

        // Use location or datacenter (prefer location)
        if (!isset($payload['location'])) {
            $payload['location'] = $defaultRegion;
        }

        // Remove null values
        $payload = array_filter($payload, fn($value) => $value !== null);

        // Log the complete server creation payload for debugging
        Log::info('Creating Hetzner server with payload', [
            'payload' => $payload,
            'payload_json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'ssh_keys' => $sshKeys,
            'ssh_key_name' => $sshKeyName,
            'config' => $config,
        ]);

        $url = $this->baseUrl . '/servers';
        $this->logRequest('POST', $url, $payload);

        $response = $this->client->post('/servers', $payload);

        $this->logResponse('POST', $url, $response);

        if ($response->failed()) {
            Log::error('Hetzner API error creating server', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $payload,
                'payload_json' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);
            throw new \RuntimeException('Failed to create Hetzner server: ' . $response->body());
        }

        return $response->json()['server'];
    }

    /**
     * Get all SSH keys.
     */
    private function getAllSshKeys(): array
    {
        $url = $this->baseUrl . '/ssh_keys';
        $this->logRequest('GET', $url, []);

        $response = $this->client->get('/ssh_keys');

        $this->logResponse('GET', $url, $response);

        if ($response->failed()) {
            Log::warning('Failed to fetch SSH keys from Hetzner', [
                'status' => $response->status(),
            ]);
            return [];
        }

        return $response->json()['ssh_keys'] ?? [];
    }

    /**
     * Get SSH key ID by name.
     */
    public function getSshKeyIdByName(string $name): ?int
    {
        $sshKeys = $this->getAllSshKeys();
        foreach ($sshKeys as $key) {
            if ($key['name'] === $name) {
                return $key['id'];
            }
        }

        return null;
    }

    /**
     * Create an SSH key in Hetzner Cloud.
     */
    public function createSshKey(string $name, string $publicKey): array
    {
        $payload = [
            'name' => $name,
            'public_key' => $publicKey,
        ];

        $url = $this->baseUrl . '/ssh_keys';
        $this->logRequest('POST', $url, $payload);

        $response = $this->client->post('/ssh_keys', $payload);

        $this->logResponse('POST', $url, $response);

        if ($response->failed()) {
            Log::error('Failed to create SSH key in Hetzner', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to create SSH key in Hetzner: ' . $response->body());
        }

        return $response->json()['ssh_key'];
    }

    /**
     * Get server details by ID.
     */
    public function getServer(int $serverId): array
    {
        $url = $this->baseUrl . "/servers/{$serverId}";
        $this->logRequest('GET', $url, []);

        $response = $this->client->get("/servers/{$serverId}");

        $this->logResponse('GET', $url, $response);

        if ($response->failed()) {
            throw new \RuntimeException("Failed to get Hetzner server {$serverId}: " . $response->body());
        }

        return $response->json()['server'];
    }

    /**
     * Map Hetzner server status to our internal status.
     */
    public function mapHetznerStatusToInternal(string $hetznerStatus): string
    {
        return match ($hetznerStatus) {
            'running' => 'active',
            'initializing', 'starting', 'rebuilding' => 'provisioning',
            'stopping', 'off', 'deleting', 'migrating', 'unknown' => 'error',
            default => 'error',
        };
    }

    /**
     * Delete a server.
     */
    public function deleteServer(int $serverId): bool
    {
        $url = $this->baseUrl . "/servers/{$serverId}";
        $this->logRequest('DELETE', $url, []);

        $response = $this->client->delete("/servers/{$serverId}");

        $this->logResponse('DELETE', $url, $response);

        if ($response->failed()) {
            Log::error('Hetzner API error deleting server', [
                'server_id' => $serverId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Reboot a server.
     */
    public function rebootServer(int $serverId): bool
    {
        $url = $this->baseUrl . "/servers/{$serverId}/actions/reboot";
        $payload = [];
        
        $this->logRequest('POST', $url, $payload);

        $response = $this->client->post("/servers/{$serverId}/actions/reboot", $payload);

        $this->logResponse('POST', $url, $response);

        if ($response->failed()) {
            Log::error('Hetzner API error rebooting server', [
                'server_id' => $serverId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get server pricing information.
     */
    public function getServerPricing(string $serverType, string $location): ?array
    {
        try {
            $serverTypes = $this->getServerTypes();
            foreach ($serverTypes as $type) {
                if ($type['name'] === $serverType && isset($type['prices'])) {
                    // Find price for the location
                    foreach ($type['prices'] as $price) {
                        if ($price['location'] === $location) {
                            return $price;
                        }
                    }
                    // Fallback to first price if location not found
                    if (!empty($type['prices'])) {
                        return $type['prices'][0];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get server pricing', [
                'server_type' => $serverType,
                'location' => $location,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * List all servers.
     */
    public function listServers(): array
    {
        $url = $this->baseUrl . '/servers';
        $this->logRequest('GET', $url, []);

        $response = $this->client->get('/servers');

        $this->logResponse('GET', $url, $response);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to list Hetzner servers: ' . $response->body());
        }

        return $response->json()['servers'] ?? [];
    }

    /**
     * Get server types (sizes).
     * Cached for 1 week to avoid excessive API calls.
     */
    public function getServerTypes(): array
    {
        return Cache::remember('hetzner_server_types', now()->addWeek(), function () {
            $allServerTypes = [];
            $page = 1;
            $perPage = 25;

            do {
                $url = $this->baseUrl . '/server_types';
                $params = ['page' => $page, 'per_page' => $perPage];
                $this->logRequest('GET', $url, $params);

                $response = $this->client->get('/server_types', $params);

                $this->logResponse('GET', $url, $response);

                if ($response->failed()) {
                    throw new \RuntimeException('Failed to get Hetzner server types: ' . $response->body());
                }

                $data = $response->json();
                $serverTypes = $data['server_types'] ?? [];
                $allServerTypes = array_merge($allServerTypes, $serverTypes);

                $meta = $data['meta'] ?? [];
                $pagination = $meta['pagination'] ?? [];
                $lastPage = $pagination['last_page'] ?? 1;

                $page++;
            } while ($page <= $lastPage);

            return $allServerTypes;
        });
    }

    /**
     * Clear the server types cache.
     */
    public function clearServerTypesCache(): void
    {
        Cache::forget('hetzner_server_types');
    }

    /**
     * Get images.
     */
    public function getImages(): array
    {
        $params = ['type' => 'system'];
        $url = $this->baseUrl . '/images';
        $this->logRequest('GET', $url, $params);

        $response = $this->client->get('/images', $params);

        $this->logResponse('GET', $url, $response);

        if ($response->failed()) {
            throw new \RuntimeException('Failed to get Hetzner images: ' . $response->body());
        }

        return $response->json()['images'] ?? [];
    }

    /**
     * Get locations.
     * Cached for 1 week to avoid excessive API calls.
     */
    public function getLocations(): array
    {
        return Cache::remember('hetzner_locations', now()->addWeek(), function () {
            $url = $this->baseUrl . '/locations';
            $this->logRequest('GET', $url, []);

            $response = $this->client->get('/locations');

            $this->logResponse('GET', $url, $response);

            if ($response->failed()) {
                throw new \RuntimeException('Failed to get Hetzner locations: ' . $response->body());
            }

            return $response->json()['locations'] ?? [];
        });
    }

    /**
     * Get server metrics.
     */
    public function getServerMetrics(int $serverId, string $type = 'cpu', string $start = null, string $end = null): array
    {
        $params = ['type' => $type];
        if ($start) {
            $params['start'] = $start;
        }
        if ($end) {
            $params['end'] = $end;
        }

        $url = $this->baseUrl . "/servers/{$serverId}/metrics";
        $this->logRequest('GET', $url, $params);

        $response = $this->client->get("/servers/{$serverId}/metrics", $params);

        $this->logResponse('GET', $url, $response);

        if ($response->failed()) {
            Log::warning('Failed to get server metrics', [
                'server_id' => $serverId,
                'status' => $response->status(),
            ]);
            return [];
        }

        return $response->json()['metrics'] ?? [];
    }

    /**
     * Get server public IP.
     */
    public function getServerPublicIp(int $serverId): ?string
    {
        $server = $this->getServer($serverId);
        return $server['public_net']['ipv4']['ip'] ?? null;
    }

    /**
     * Wait for server to be ready.
     */
    public function waitForServer(int $serverId, int $timeout = 300): bool
    {
        $start = time();
        while (time() - $start < $timeout) {
            try {
                $server = $this->getServer($serverId);
                if ($server['status'] === 'running') {
                    return true;
                }
                sleep(5);
            } catch (\Exception $e) {
                Log::warning('Error checking server status', [
                    'server_id' => $serverId,
                    'error' => $e->getMessage(),
                ]);
                sleep(5);
            }
        }

        return false;
    }

    /**
     * Log HTTP request details.
     */
    private function logRequest(string $method, string $url, array $payload = []): void
    {
        Log::info('Hetzner API Request', [
            'method' => $method,
            'url' => $url,
            'headers' => [
                'Authorization' => 'Bearer ' . substr($this->token, 0, 10) . '...' . substr($this->token, -4),
                'Content-Type' => 'application/json',
            ],
            'payload' => $payload,
            'payload_json' => json_encode($payload, JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * Log HTTP response details.
     */
    private function logResponse(string $method, string $url, $response): void
    {
        $responseData = [
            'method' => $method,
            'url' => $url,
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
        ];

        try {
            $responseData['json'] = $response->json();
        } catch (\Exception $e) {
            // Response is not JSON
        }

        if ($response->successful()) {
            Log::info('Hetzner API Response', $responseData);
        } else {
            Log::error('Hetzner API Error Response', $responseData);
        }
    }
}

