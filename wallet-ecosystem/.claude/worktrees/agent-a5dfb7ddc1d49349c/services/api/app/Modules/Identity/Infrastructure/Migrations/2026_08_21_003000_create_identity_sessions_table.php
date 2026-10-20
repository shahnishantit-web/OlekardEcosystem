<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->create('identity.sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('identity.users');
            // device_id FK added after identity.devices is created (migration 004)
            $table->uuid('device_id')->nullable();
            $table->string('refresh_token_hash', 64)->unique();
            $table->uuid('refresh_token_family_id');
            $table->string('status', 20)->default('active');
            $table->string('actor_type', 20);
            $table->timestampTz('step_up_verified_at')->nullable();
            $table->string('step_up_scope', 200)->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampTz('expires_at');
            $table->string('ip_hash', 64)->nullable();   // HMAC-SHA256(platform_secret, ip) — never raw IP
            $table->string('user_agent', 500)->nullable();
            $table->timestampsTz();

            $table->index('user_id');
            $table->index(['user_id', 'status']);
            $table->index(['status', 'updated_at']);
            $table->index('refresh_token_family_id');
        });
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('identity.sessions');
    }
};
