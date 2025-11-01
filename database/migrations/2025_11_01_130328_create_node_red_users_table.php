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
        Schema::create('node_red_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_red_instance_id')->constrained()->onDelete('cascade');
            $table->string('username');
            $table->string('password_hash'); // bcrypt hash
            $table->string('permissions')->default('*'); // * for all, or specific permissions
            $table->timestamps();

            $table->unique(['node_red_instance_id', 'username']);
            $table->index('node_red_instance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('node_red_users');
    }
};
