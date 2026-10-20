<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        Schema::connection('identity')->create('device_security_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('device_id')->nullable()->index();
            $table->uuid('user_id');
            $table->string('event_type', 50);
            $table->string('fingerprint_hash', 64)->nullable();
            $table->string('previous_fingerprint_hash', 64)->nullable();
            $table->jsonb('risk_signals')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('acknowledgment_expires_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestampsTz();
        });

        DB::connection('identity')->statement(
            'CREATE INDEX idx_device_security_events_user_type_created ON identity.device_security_events (user_id, event_type, created_at DESC)'
        );
        DB::connection('identity')->statement(
            'CREATE INDEX idx_device_security_events_device_type ON identity.device_security_events (device_id, event_type) WHERE device_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('identity')->dropIfExists('device_security_events');
    }
};
