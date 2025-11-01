<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('provider_id')->nullable()->unique()->comment('Hetzner server ID');
            $table->string('name');
            $table->string('public_ip')->nullable();
            $table->string('private_ip')->nullable();
            $table->string('region')->default('nbg1');
            $table->string('server_type')->nullable()->comment('Hetzner server type (e.g., cx11)');
            $table->integer('ram_mb_total')->default(0);
            $table->integer('disk_gb_total')->default(0);
            $table->integer('ram_mb_used')->default(0);
            $table->integer('disk_gb_used')->default(0);
            $table->string('status')->default('pending')->comment('pending, provisioning, active, error, deleted');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
