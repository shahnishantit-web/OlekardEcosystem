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
        Schema::connection('platform')->create('withdrawal.withdrawal_provider_callbacks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('withdrawal_transaction_id')->notNullable();
            $table->string('provider', 64)->notNullable();
            $table->string('provider_reference_id', 255)->notNullable();
            $table->string('status', 32)->notNullable();
            $table->jsonb('raw_payload')->notNullable();
            $table->boolean('signature_valid')->notNullable();
            $table->timestampTz('received_at')->notNullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE withdrawal.withdrawal_provider_callbacks ADD CONSTRAINT wpc_status_check CHECK (status IN ('success','failure','unknown'))");

        DB::statement("CREATE INDEX wpc_transaction_idx ON withdrawal.withdrawal_provider_callbacks (withdrawal_transaction_id)");
        DB::statement("CREATE INDEX wpc_provider_reference_idx ON withdrawal.withdrawal_provider_callbacks (provider_reference_id)");
        DB::statement("CREATE INDEX wpc_received_at_idx ON withdrawal.withdrawal_provider_callbacks (received_at DESC)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('withdrawal.withdrawal_provider_callbacks');
    }
};
