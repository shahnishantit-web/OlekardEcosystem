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
        Schema::connection('identity')->create('identity.devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('identity.users');
            $table->string('device_fingerprint', 64);    // SHA-256 hash only — never raw fingerprint
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 20);
            $table->string('status', 20)->default('pending_verification');
            $table->timestampTz('trusted_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'device_fingerprint']);
            $table->index('user_id');
        });

        // Add device_id FK on sessions now that devices table exists
        Schema::connection('identity')->table('identity.sessions', function (Blueprint $table): void {
            $table->foreign('device_id')
                ->references('id')
                ->on('identity.devices')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection('identity')->table('identity.sessions', function (Blueprint $table): void {
            $table->dropForeign(['device_id']);
        });
        Schema::connection('identity')->dropIfExists('identity.devices');
    }
};
