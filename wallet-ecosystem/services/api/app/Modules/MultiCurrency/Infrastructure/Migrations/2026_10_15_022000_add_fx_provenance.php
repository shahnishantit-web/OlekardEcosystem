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
        Schema::connection('platform')->table('multi_currency.fx_rates', function (Blueprint $t): void {
            $t->string('source', 16)->default('manual');
            $t->string('provider', 100)->nullable();
            $t->timestampTz('observed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->table('multi_currency.fx_rates', fn (Blueprint $t) => $t->dropColumn(['source', 'provider', 'observed_at']));
    }
};
