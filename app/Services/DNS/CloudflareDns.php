<?php

namespace App\Services\DNS;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareDns
{
    private PendingRequest $client;
    private string $zoneId;
    private bool $proxied;

    public function __construct()
    {
        $apiToken = config('provisioning.cloudflare.api_token');
        $this->zoneId = config('provisioning.cloudflare.zone_id');
        $this->proxied = config('provisioning.cloudflare.proxied', false);

        if (empty($apiToken)) {
            throw new \RuntimeException('Cloudflare API token is not configured');
        }

        if (empty($this->zoneId)) {
            throw new \RuntimeException('Cloudflare Zone ID is not configured');
        }

        $this->client = Http::withHeaders([
            'Authorization' => "Bearer {$apiToken}",
            'Content-Type' => 'application/json',
        ])->baseUrl('https://api.cloudflare.com/client/v4');
    }

    /**
     * Create or update an A record.
     */
    public function ensureARecord(string $subdomain, string $ipAddress, bool $proxied = null): array
    {
        $fqdn = $this->getFqdn($subdomain);
        $proxied = $proxied ?? $this->proxied;

        // Check if record exists
        $existingRecord = $this->findRecord($subdomain, 'A');

        if ($existingRecord) {
            // Update existing record
            return $this->updateRecord($existingRecord['id'], [
                'content' => $ipAddress,
                'proxied' => $proxied,
            ]);
        }

        // Create new record
        return $this->createRecord([
            'type' => 'A',
            'name' => $subdomain,
            'content' => $ipAddress,
            'proxied' => $proxied,
            'ttl' => 1, // Auto TTL
        ]);
    }

    /**
     * Delete a DNS record.
     */
    public function deleteRecord(string $recordId): bool
    {
        $response = $this->client->delete("/zones/{$this->zoneId}/dns_records/{$recordId}");

        if ($response->failed()) {
            Log::error('Cloudflare DNS delete failed', [
                'record_id' => $recordId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        }

        return $response->json()['success'] ?? false;
    }

    /**
     * Find a DNS record by name and type.
     */
    public function findRecord(string $subdomain, string $type = 'A'): ?array
    {
        $response = $this->client->get("/zones/{$this->zoneId}/dns_records", [
            'name' => $this->getFqdn($subdomain),
            'type' => $type,
        ]);

        if ($response->failed()) {
            Log::warning('Cloudflare DNS lookup failed', [
                'subdomain' => $subdomain,
                'status' => $response->status(),
            ]);
            return null;
        }

        $results = $response->json()['result'] ?? [];
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Create a DNS record.
     */
    private function createRecord(array $data): array
    {
        $response = $this->client->post("/zones/{$this->zoneId}/dns_records", $data);

        if ($response->failed()) {
            Log::error('Cloudflare DNS create failed', [
                'data' => $data,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to create Cloudflare DNS record: ' . $response->body());
        }

        $result = $response->json()['result'] ?? null;
        if (!$result) {
            throw new \RuntimeException('Invalid response from Cloudflare API');
        }

        return $result;
    }

    /**
     * Update a DNS record.
     */
    private function updateRecord(string $recordId, array $data): array
    {
        $response = $this->client->patch("/zones/{$this->zoneId}/dns_records/{$recordId}", $data);

        if ($response->failed()) {
            Log::error('Cloudflare DNS update failed', [
                'record_id' => $recordId,
                'data' => $data,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to update Cloudflare DNS record: ' . $response->body());
        }

        $result = $response->json()['result'] ?? null;
        if (!$result) {
            throw new \RuntimeException('Invalid response from Cloudflare API');
        }

        return $result;
    }

    /**
     * Get FQDN from subdomain.
     */
    private function getFqdn(string $subdomain): string
    {
        $baseDomain = config('provisioning.dns.base_domain', 'nodereds.com');
        return $subdomain . '.' . $baseDomain;
    }

    /**
     * Get zone details.
     */
    public function getZone(): array
    {
        $response = $this->client->get("/zones/{$this->zoneId}");

        if ($response->failed()) {
            throw new \RuntimeException('Failed to get Cloudflare zone: ' . $response->body());
        }

        return $response->json()['result'] ?? [];
    }
}

