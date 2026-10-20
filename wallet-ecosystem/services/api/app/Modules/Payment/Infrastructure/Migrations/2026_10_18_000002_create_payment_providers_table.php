<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payment.payment_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique()->notNullable();
            $table->string('display_name', 255)->notNullable();
            $table->string('adapter_identifier', 128)->notNullable();
            $table->string('adapter_version', 32)->notNullable();
            $table->string('environment', 32)->notNullable();
            $table->boolean('is_enabled')->default(false)->notNullable();
            $table->boolean('is_suspended')->default(false)->notNullable();
            $table->smallInteger('sort_order')->default(0)->notNullable();
            $table->boolean('is_default')->default(false)->notNullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
            $table->timestampTz('updated_at')->notNullable()->useCurrent();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_providers ADD CONSTRAINT providers_environment_check
             CHECK (environment IN ('sandbox','production','mock'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX providers_enabled_suspended_order_idx
             ON payment.payment_providers (is_enabled, is_suspended, sort_order)'
        );

        // Seed: OleKard Wallet
        DB::connection('payment')->table('payment.payment_providers')->insert([
            'id'                 => (string) Uuid::uuid7(),
            'code'               => 'olekard_wallet',
            'display_name'       => 'OleKard Wallet',
            'adapter_identifier' => 'olekard_wallet',
            'adapter_version'    => '1.0.0',
            'environment'        => 'production',
            'is_enabled'         => false,
            'is_suspended'       => false,
            'sort_order'         => 10,
            'is_default'         => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Seed: Development Mock Bank (local/testing only)
        DB::connection('payment')->table('payment.payment_providers')->insert([
            'id'                 => (string) Uuid::uuid7(),
            'code'               => 'mock_bank',
            'display_name'       => 'Development Mock Bank — NOT FOR PRODUCTION',
            'adapter_identifier' => 'mock_bank',
            'adapter_version'    => '1.0.0',
            'environment'        => 'mock',
            'is_enabled'         => false,
            'is_suspended'       => false,
            'sort_order'         => 99,
            'is_default'         => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment.payment_providers');
    }
};
