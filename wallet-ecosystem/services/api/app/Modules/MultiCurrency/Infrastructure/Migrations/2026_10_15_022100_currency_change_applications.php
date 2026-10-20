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
        Schema::connection('platform')->create('multi_currency.currency_change_applications', function (Blueprint $t): void {
            $t->uuid('change_request_id')->primary();
            $t->string('currency_code', 3);
            $t->uuid('checker_id');
            $t->timestampTz('applied_at');
        });
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('multi_currency.currency_change_applications');
    }
};
