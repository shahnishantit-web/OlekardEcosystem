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
        Schema::connection('platform')->table('multi_currency.currency_wallets', function (Blueprint $table): void {
            $table->string('idempotency_key', 255)->unique()->after('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('multi_currency.currency_wallets', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
    }
};
