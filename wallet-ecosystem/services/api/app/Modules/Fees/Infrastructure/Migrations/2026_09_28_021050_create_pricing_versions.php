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
        Schema::connection('platform')->create('fees.pricing_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label', 150);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_published')->default(false);
            $table->string('created_by', 36);
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            "CREATE INDEX idx_pricing_versions_effective
             ON fees.pricing_versions (effective_from, effective_to)
             WHERE is_published = true"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('fees.pricing_versions');
    }
};
