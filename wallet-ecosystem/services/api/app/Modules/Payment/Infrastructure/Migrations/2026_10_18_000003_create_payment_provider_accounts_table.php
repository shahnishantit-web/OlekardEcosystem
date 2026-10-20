<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payment.payment_provider_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('provider_id')->notNullable();
            $table->string('merchant_account_reference', 255)->notNullable();
            $table->jsonb('supported_currencies')->default('[]')->notNullable();
            $table->jsonb('supported_payment_methods')->default('[]')->notNullable();
            $table->bigInteger('min_amount_minor')->default(100)->notNullable();
            $table->bigInteger('max_amount_minor')->default(100000000)->notNullable();
            $table->jsonb('enabled_organisations')->default('[]')->notNullable();
            $table->jsonb('enabled_modules')->default('[]')->notNullable();
            $table->jsonb('country_restrictions')->default('[]')->notNullable();
            $table->string('callback_url', 500)->nullable();
            $table->string('callback_secret_reference', 500)->nullable();
            $table->jsonb('capability_flags')->default('{}')->notNullable();
            $table->jsonb('fee_rule_ids')->default('[]')->notNullable();
            $table->bigInteger('daily_limit_minor')->nullable();
            $table->boolean('is_active')->default(true)->notNullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            'ALTER TABLE payment.payment_provider_accounts
             ADD CONSTRAINT provider_accounts_provider_id_fk
             FOREIGN KEY (provider_id) REFERENCES payment.payment_providers(id)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX provider_accounts_provider_id_idx
             ON payment.payment_provider_accounts (provider_id)'
        );
        DB::connection('payment')->statement(
            'CREATE INDEX provider_accounts_is_active_idx
             ON payment.payment_provider_accounts (is_active)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_provider_accounts');
    }
};
