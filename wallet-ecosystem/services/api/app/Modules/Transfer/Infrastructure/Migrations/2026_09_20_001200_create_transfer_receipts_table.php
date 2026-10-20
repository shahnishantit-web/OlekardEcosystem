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
        Schema::connection('platform')->create('transfer.transfer_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transfer_transaction_id')->notNullable();
            $table->uuid('receipt_reference')->notNullable();
            $table->uuid('sender_customer_id')->notNullable();
            $table->uuid('recipient_customer_id')->notNullable();
            $table->char('currency_code', 3)->notNullable();
            $table->bigInteger('gross_amount')->notNullable();
            $table->bigInteger('fee_amount')->notNullable();
            $table->bigInteger('net_amount')->notNullable();
            $table->timestampTz('issued_at')->notNullable();
        });

        \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX tr_transaction_id_idx ON transfer.transfer_receipts (transfer_transaction_id)");
        \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX tr_receipt_reference_idx ON transfer.transfer_receipts (receipt_reference)");
        \Illuminate\Support\Facades\DB::statement("CREATE INDEX tr_sender_idx ON transfer.transfer_receipts (sender_customer_id)");
        \Illuminate\Support\Facades\DB::statement("CREATE INDEX tr_recipient_idx ON transfer.transfer_receipts (recipient_customer_id)");
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('transfer.transfer_receipts');
    }
};
