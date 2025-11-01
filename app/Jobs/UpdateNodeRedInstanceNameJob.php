<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\NodeRedInstance;
use App\Services\DNS\CloudflareDns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UpdateNodeRedInstanceNameJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(
        public readonly int $instanceId,
        public readonly string $newSubdomain,
        public readonly ?int $userId = null
    ) {
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $oldSubdomain = $instance->subdomain;
        $oldFqdn = $instance->fqdn;

        // Validate subdomain format
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $this->newSubdomain)) {
            throw new \InvalidArgumentException('Invalid subdomain format. Only lowercase letters, numbers, and hyphens are allowed.');
        }

        // Check if subdomain is already taken
        $existing = NodeRedInstance::where('subdomain', $this->newSubdomain)
            ->where('id', '!=', $instance->id)
            ->first();

        if ($existing) {
            throw new \RuntimeException("Subdomain '{$this->newSubdomain}' is already taken.");
        }

        // Update slug and FQDN
        $baseDomain = config('provisioning.dns.base_domain', 'nodereds.com');
        $newSlug = Str::slug($this->newSubdomain);
        $newFqdn = $this->newSubdomain . '.' . $baseDomain;

        // Delete old DNS record if it exists
        if ($instance->domains()->exists()) {
            $oldDomain = $instance->domains()->first();
            if ($oldDomain && $oldDomain->provider_record_id) {
                try {
                    $dns = new CloudflareDns();
                    $dns->deleteRecord($oldDomain->provider_record_id);
                    
                    Log::info('Old DNS record deleted', [
                        'instance_id' => $instance->id,
                        'old_subdomain' => $oldSubdomain,
                        'old_record_id' => $oldDomain->provider_record_id,
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete old DNS record', [
                        'instance_id' => $instance->id,
                        'old_subdomain' => $oldSubdomain,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue anyway - DNS can be cleaned up manually
                }
            }
        }

        // Create new DNS record
        if ($instance->server && $instance->server->public_ip) {
            try {
                $dns = new CloudflareDns();
                $dnsRecord = $dns->ensureARecord($this->newSubdomain, $instance->server->public_ip);

                Log::info('New DNS record created', [
                    'instance_id' => $instance->id,
                    'new_subdomain' => $this->newSubdomain,
                    'new_record_id' => $dnsRecord['id'] ?? 'unknown',
                ]);

                // Update or create domain record
                Domain::updateOrCreate(
                    ['node_red_instance_id' => $instance->id],
                    [
                        'hostname' => $this->newSubdomain,
                        'fqdn' => $newFqdn,
                        'provider' => 'cloudflare',
                        'provider_record_id' => (string) ($dnsRecord['id'] ?? ''),
                        'ssl_status' => 'pending',
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Failed to create new DNS record', [
                    'instance_id' => $instance->id,
                    'new_subdomain' => $this->newSubdomain,
                    'error' => $e->getMessage(),
                ]);
                throw new \RuntimeException('Failed to create DNS record: ' . $e->getMessage());
            }
        }

        // Update instance
        $instance->update([
            'subdomain' => $this->newSubdomain,
            'slug' => $newSlug,
            'fqdn' => $newFqdn,
        ]);

        Log::info('Instance name updated successfully', [
            'instance_id' => $instance->id,
            'old_subdomain' => $oldSubdomain,
            'new_subdomain' => $this->newSubdomain,
            'user_id' => $this->userId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('UpdateNodeRedInstanceNameJob failed permanently', [
            'instance_id' => $this->instanceId,
            'new_subdomain' => $this->newSubdomain,
            'error' => $exception->getMessage(),
        ]);
    }
}

