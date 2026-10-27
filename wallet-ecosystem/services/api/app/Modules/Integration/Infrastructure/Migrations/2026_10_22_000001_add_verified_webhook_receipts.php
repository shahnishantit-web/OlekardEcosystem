<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->table('integration.webhook_events', function (Blueprint $table): void {
            $table->jsonb('verified_notification')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->text('encrypted_evidence')->nullable();
        });
        DB::connection('platform')->statement('CREATE UNIQUE INDEX uq_payment_verified_event ON integration.webhook_events (idempotency_key) WHERE verified_notification IS NOT NULL');
    }

    public function down(): void
    {
        DB::connection('platform')->statement('DROP INDEX IF EXISTS integration.uq_payment_verified_event');
        Schema::connection('platform')->table('integration.webhook_events', function (Blueprint $table): void {
            $table->dropColumn(['verified_notification', 'payload_hash', 'encrypted_evidence']);
        });
    }
};
