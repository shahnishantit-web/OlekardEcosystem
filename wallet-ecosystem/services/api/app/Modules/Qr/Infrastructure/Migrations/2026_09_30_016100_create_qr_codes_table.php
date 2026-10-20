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
        Schema::connection('platform')->create('qr.qr_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->notNullable()->index();
            $table->string('type', 20)->notNullable();
            $table->string('label', 100)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->char('currency', 3)->notNullable()->default('BWP');
            $table->string('merchant_ref', 255)->nullable();
            $table->uuid('nonce')->unique()->notNullable();
            $table->text('payload')->notNullable();
            $table->string('status', 20)->notNullable()->default('active');
            $table->uuid('reserved_by_customer_id')->nullable();
            $table->timestampTz('reserved_until')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "ALTER TABLE qr.qr_codes
             ADD CONSTRAINT qr_codes_type_check CHECK (type IN ('static','dynamic'))"
        );
        DB::connection('platform')->statement(
            "ALTER TABLE qr.qr_codes
             ADD CONSTRAINT qr_codes_status_check CHECK (status IN ('active','reserved','used','expired','inactive'))"
        );
        DB::connection('platform')->statement(
            'CREATE INDEX qr_codes_merchant_status_idx ON qr.qr_codes (merchant_id, status)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX qr_codes_status_reserved_until_idx ON qr.qr_codes (status, reserved_until)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX qr_codes_status_expires_at_idx ON qr.qr_codes (status, expires_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('qr.qr_codes');
    }
};
