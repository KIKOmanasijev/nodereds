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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_red_instance_id')->constrained()->onDelete('cascade');
            $table->string('hostname');
            $table->string('fqdn')->unique();
            $table->string('provider')->default('cloudflare');
            $table->string('provider_record_id')->nullable();
            $table->string('ssl_status')->default('pending')->comment('pending, active, error, expired');
            $table->timestamp('ssl_issued_at')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamps();

            $table->index(['node_red_instance_id']);
            $table->index(['provider', 'provider_record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
