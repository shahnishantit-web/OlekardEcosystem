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
        Schema::connection('platform')->create('multi_currency.currencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 3)->unique();
            $table->string('name', 100);
            $table->string('symbol', 10);
            $table->smallInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "CREATE INDEX idx_currencies_active ON multi_currency.currencies (is_active) WHERE is_active = true"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('multi_currency.currencies');
    }
};
