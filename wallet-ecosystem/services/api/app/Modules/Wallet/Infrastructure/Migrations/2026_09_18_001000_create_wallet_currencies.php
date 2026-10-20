<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'wallet';

    public function up(): void
    {
        DB::connection('wallet')->statement('CREATE SCHEMA IF NOT EXISTS wallet');

        Schema::connection('wallet')->create('wallet.currencies', function (Blueprint $table): void {
            $table->string('code', 10)->primary();
            $table->string('name', 100);
            $table->smallInteger('precision')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_tbd')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('wallet')->dropIfExists('wallet.currencies');
    }
};
