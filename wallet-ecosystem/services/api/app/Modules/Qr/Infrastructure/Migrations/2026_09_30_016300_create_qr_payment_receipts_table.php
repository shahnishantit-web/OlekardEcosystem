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
        Schema::connection('platform')->create('qr.qr_payment_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('receipt_ref', 50)->notNullable()->unique();
            $table->uuid('qr_payment_transaction_id')->notNullable()->unique();
            $table->string('merchant_display_name', 255)->notNullable();
            $table->string('merchant_ref', 255)->nullable();
            $table->bigInteger('amount')->notNullable();
            $table->bigInteger('fee')->notNullable()->default(0);
            $table->bigInteger('total_debited')->notNullable();
            $table->char('currency', 3)->notNullable()->default('BWP');
            $table->timestampTz('issued_at')->notNullable();
            $table->timestampTz('created_at')->notNullable()->useCurrent();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX qr_payment_receipts_transaction_idx ON qr.qr_payment_receipts (qr_payment_transaction_id)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('qr.qr_payment_receipts');
    }
};
