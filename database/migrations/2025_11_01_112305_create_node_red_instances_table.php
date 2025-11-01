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
        Schema::create('node_red_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('server_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('plan_id')->constrained()->onDelete('restrict');
            $table->string('slug')->unique();
            $table->string('subdomain')->unique();
            $table->string('fqdn')->unique();
            $table->integer('memory_mb');
            $table->integer('storage_gb');
            $table->string('admin_user')->default('admin');
            $table->string('admin_pass_hash');
            $table->string('credential_secret')->nullable()->comment('Node-RED credentialSecret');
            $table->string('status')->default('pending')->comment('pending, deploying, active, error, stopped, deleted');
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_red_instances');
    }
};
