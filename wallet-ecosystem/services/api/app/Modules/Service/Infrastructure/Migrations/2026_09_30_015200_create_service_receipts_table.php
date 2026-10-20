<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('service.service_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id')->notNullable()->unique();
            $table->string('receipt_reference', 255)->notNullable()->unique();
            $table->string('service_name', 255)->notNullable();
            $table->string('customer_reference', 255)->notNullable();
            $table->string('provider_confirmation_reference', 255)->nullable();
            $table->bigInteger('amount')->notNullable();
            $table->bigInteger('fee_amount')->notNullable()->default(0);
            $table->bigInteger('total_amount')->notNullable();
            $table->char('currency_code', 10)->notNullable();
            $table->timestampTz('issued_at')->notNullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('service.service_receipts');
    }
};
