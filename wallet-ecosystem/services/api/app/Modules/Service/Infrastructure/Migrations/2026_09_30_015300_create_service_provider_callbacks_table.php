<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('service.service_provider_callbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id')->nullable();
            $table->string('provider_code', 100)->notNullable();
            $table->string('provider_reference', 255)->notNullable();
            $table->string('status', 50)->notNullable();
            $table->jsonb('raw_payload')->notNullable();
            $table->string('signature', 500)->nullable();
            $table->boolean('processed')->notNullable()->default(false);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE service.service_provider_callbacks
             ADD CONSTRAINT service_provider_callbacks_unique
             UNIQUE (provider_code, provider_reference)"
        );
        DB::connection('platform')->statement(
            "CREATE INDEX service_provider_callbacks_tx_idx
             ON service.service_provider_callbacks (transaction_id)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('service.service_provider_callbacks');
    }
};
