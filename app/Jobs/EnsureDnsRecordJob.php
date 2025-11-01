<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\NodeRedInstance;
use App\Services\DNS\CloudflareDns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EnsureDnsRecordJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $instanceId
    ) {
    }

    public function handle(): void
    {
        $instance = NodeRedInstance::findOrFail($this->instanceId);
        $instance->load(['server', 'domains']);

        if (!$instance->server || !$instance->server->public_ip) {
            Log::warning('Instance has no server or public IP', ['instance_id' => $instance->id]);
            return;
        }

        try {
            $dns = new CloudflareDns();
            $dnsRecord = $dns->ensureARecord($instance->subdomain, $instance->server->public_ip);

            // Update or create domain record
            Domain::updateOrCreate(
                ['node_red_instance_id' => $instance->id],
                [
                    'hostname' => $instance->subdomain,
                    'fqdn' => $instance->fqdn,
                    'provider' => 'cloudflare',
                    'provider_record_id' => (string) $dnsRecord['id'],
                ]
            );
        } catch (\Exception $e) {
            Log::error('DNS record creation failed', [
                'instance_id' => $instance->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
