<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Perfect for small projects and testing',
                'memory_mb' => 256,
                'storage_gb' => 5,
                'cpu_count' => 1,
                'cpu_limit' => 0.5,
                'monthly_price_cents' => 500,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Standard',
                'slug' => 'standard',
                'description' => 'Great for medium-sized projects',
                'memory_mb' => 512,
                'storage_gb' => 10,
                'cpu_count' => 1,
                'cpu_limit' => 1.0,
                'monthly_price_cents' => 1000,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'For larger projects with high traffic',
                'memory_mb' => 1024,
                'storage_gb' => 20,
                'cpu_count' => 2,
                'cpu_limit' => 2.0,
                'monthly_price_cents' => 2000,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
