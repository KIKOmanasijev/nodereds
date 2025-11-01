<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\Hetzner\HetznerClient;
use App\Services\Scheduling\CapacityPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreateServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'server:create {--plan-id= : Plan ID to use for server sizing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Hetzner server and configure it for Node-RED instances';

    /**
     * Execute the console command.
     */
    public function handle(CapacityPlanner $capacityPlanner): int
    {
        // FORBIDDEN: Do not create Hetzner servers in local environment
        if (app()->environment('local')) {
            $this->error('Server creation is forbidden in local environment.');
            $this->info('Please set HARDCODED_SERVER_ID in .env to use an existing server.');
            $this->info('Or run this command in production environment.');
            return Command::FAILURE;
        }

        $this->info('Creating a new Hetzner server...');

        // Get plan (use first available plan or specified plan)
        $planId = $this->option('plan-id');
        if ($planId) {
            $plan = Plan::findOrFail($planId);
        } else {
            $plan = Plan::first();
            if (!$plan) {
                $this->error('No plans found. Please create a plan first.');
                return Command::FAILURE;
            }
        }

        $this->info("Using plan: {$plan->name} (Memory: {$plan->memory_mb}MB, Storage: {$plan->storage_gb}GB)");

        try {
            // Create the server
            $server = $capacityPlanner->createNewServer($plan);

            $this->info("✓ Server created successfully!");
            $this->info("  Server ID: {$server->id}");
            $this->info("  Server Name: {$server->name}");
            $this->info("  Public IP: {$server->public_ip}");
            $this->info("  Status: {$server->status}");

            // Update config to use this server
            $this->info("\nUpdating configuration to use this server for all future provisions...");
            
            // Update .env file
            $envPath = base_path('.env');
            $envContent = file_get_contents($envPath);
            
            // Remove old HARDCODED_SERVER_ID if exists
            $envContent = preg_replace('/^HARDCODED_SERVER_ID=.*$/m', '', $envContent);
            
            // Add new HARDCODED_SERVER_ID
            $envContent .= "\nHARDCODED_SERVER_ID={$server->id}\n";
            
            file_put_contents($envPath, $envContent);
            
            $this->info("✓ Configuration updated!");
            $this->info("  Set HARDCODED_SERVER_ID={$server->id} in .env");
            $this->warn("\n⚠️  Note: The server is currently provisioning. It will be ready once Traefik is bootstrapped.");
            $this->info("   You can check the server status in the admin dashboard or run: php artisan tinker");
            $this->info("   Then: App\Models\Server::find({$server->id})->status");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create server: {$e->getMessage()}");
            Log::error('Server creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

