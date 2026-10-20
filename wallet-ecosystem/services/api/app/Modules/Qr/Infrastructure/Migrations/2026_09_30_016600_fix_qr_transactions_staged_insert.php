<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        // The QrPaymentService creates an initial "initiated" record before resolving
        // the QR code (to establish an audit trail). At that point the merchant wallet
        // ID and final amount are unknown, so both need to tolerate placeholder values.
        DB::connection('platform')->statement(
            'ALTER TABLE qr.qr_payment_transactions ALTER COLUMN merchant_wallet_id DROP NOT NULL'
        );
        DB::connection('platform')->statement(
            'ALTER TABLE qr.qr_payment_transactions DROP CONSTRAINT IF EXISTS qr_payment_transactions_amount_check'
        );
        DB::connection('platform')->statement(
            'ALTER TABLE qr.qr_payment_transactions ADD CONSTRAINT qr_payment_transactions_amount_check CHECK (amount >= 0)'
        );
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'ALTER TABLE qr.qr_payment_transactions ALTER COLUMN merchant_wallet_id SET NOT NULL'
        );
        DB::connection('platform')->statement(
            'ALTER TABLE qr.qr_payment_transactions DROP CONSTRAINT IF EXISTS qr_payment_transactions_amount_check'
        );
        DB::connection('platform')->statement(
            'ALTER TABLE qr.qr_payment_transactions ADD CONSTRAINT qr_payment_transactions_amount_check CHECK (amount > 0)'
        );
    }
};
