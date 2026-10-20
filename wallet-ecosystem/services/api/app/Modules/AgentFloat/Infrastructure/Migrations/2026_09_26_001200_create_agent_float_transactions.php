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
        Schema::connection('platform')->create('agent.float_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('agent_id');
            $table->uuid('float_wallet_id');
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->char('currency_code', 3);
            $table->string('direction', 10);
            $table->uuid('related_party_id')->nullable();
            $table->string('related_party_type', 20)->nullable();
            $table->string('reference', 64)->unique();
            $table->uuid('journal_id')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->string('status', 20)->default('completed');
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_type_check
             CHECK (type IN ('funding','cash_in','cash_out'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_direction_check
             CHECK (direction IN ('debit','credit'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_related_party_type_check
             CHECK (related_party_type IS NULL OR related_party_type IN ('customer','admin'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_status_check
             CHECK (status IN ('completed','failed','reversed'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_amount_check
             CHECK (amount > 0)"
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_agent_fk
             FOREIGN KEY (agent_id) REFERENCES agent.agents(id)'
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.float_transactions ADD CONSTRAINT float_txns_float_wallet_fk
             FOREIGN KEY (float_wallet_id) REFERENCES agent.float_wallets(id)'
        );

        // Primary query pattern: paginated history per agent ordered by created_at
        DB::connection('platform')->statement(
            'CREATE INDEX float_txns_agent_created_at_idx ON agent.float_transactions (agent_id, created_at DESC)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX float_txns_type_idx ON agent.float_transactions (agent_id, type, created_at DESC)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.float_transactions');
    }
};
