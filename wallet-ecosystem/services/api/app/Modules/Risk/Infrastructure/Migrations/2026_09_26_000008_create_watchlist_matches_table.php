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
        Schema::create('risk.watchlist_matches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('watchlist_entry_id');
            $table->string('subject_type', 30);
            $table->uuid('subject_id')->index();
            $table->decimal('match_score', 5, 2);
            $table->string('match_method', 30);
            $table->timestampTz('screened_at');
            $table->uuid('alert_id')->nullable();
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampTz('created_at');

            $table->foreign('watchlist_entry_id')->references('id')->on('risk.watchlist_entries');
        });

        DB::statement(
            'CREATE UNIQUE INDEX risk_watchlist_match_active_unique ON risk.watchlist_matches (watchlist_entry_id, subject_type, subject_id) WHERE cleared_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('risk.watchlist_matches');
    }
};
