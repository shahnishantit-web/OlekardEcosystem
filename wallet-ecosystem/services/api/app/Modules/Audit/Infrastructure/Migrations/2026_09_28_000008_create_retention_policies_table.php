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
        Schema::connection('platform')->create('audit.retention_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('data_category', 64)->unique();
            $table->integer('minimum_retention_days');
            $table->string('post_retention_action', 8);
            $table->date('effective_from')->default(DB::raw('CURRENT_DATE'));
            $table->date('enforcement_hold_until')->nullable();
            $table->uuid('configured_by_actor_id');
            $table->integer('previous_minimum_retention_days')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'ALTER TABLE audit.retention_policies ADD CONSTRAINT retention_policies_minimum_retention_days_positive CHECK (minimum_retention_days > 0)'
        );
        DB::connection('platform')->statement(
            "ALTER TABLE audit.retention_policies ADD CONSTRAINT retention_policies_post_retention_action_check CHECK (post_retention_action IN ('archive', 'purge'))"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('audit.retention_policies');
    }
};
