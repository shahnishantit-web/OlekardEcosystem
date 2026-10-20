<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk.watchlist_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('entry_type', 50);
            $table->string('entity_type', 30)->nullable();
            $table->string('full_name', 500);
            $table->string('normalized_name', 500);
            $table->jsonb('aliases')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->char('nationality', 2)->nullable();
            $table->jsonb('id_numbers')->nullable();
            $table->string('source', 255);
            $table->string('source_ref', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('listed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['entry_type', 'is_active']);
        });

        DB::statement('CREATE INDEX risk_watchlist_normalized_name_trgm ON risk.watchlist_entries USING GIN (normalized_name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.watchlist_entries');
    }
};
