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
        DB::connection('platform')->statement('CREATE SCHEMA IF NOT EXISTS community_services');
        $schema = Schema::connection('community_services');
        $schema->create('community_services.event_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organisation_id')->index();
            $table->uuid('created_by');
            $table->string('title', 200);
            $table->string('organiser_name', 200);
            $table->jsonb('sponsors');
            $table->unsignedInteger('capacity');
            $table->char('currency_code', 3);
            $table->string('timezone', 100);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('sales_start_at');
            $table->timestampTz('sales_end_at');
            $table->text('cancellation_policy');
            $table->text('transfer_policy');
            $table->text('entry_policy');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        $schema->create('community_services.event_draft_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('name', 120);
            $table->unsignedInteger('capacity');
            $table->bigInteger('price_minor');
            $table->timestampsTz();
            $table->unique(['event_id', 'name'], 'event_draft_category_name_uq');
            $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
        });
        $schema->create('community_services.event_draft_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->index();
            $table->uuid('actor_id');
            $table->string('action', 30);
            $table->unsignedInteger('version');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        $schema->dropIfExists('community_services.event_draft_audit');
        $schema->dropIfExists('community_services.event_draft_categories');
        $schema->dropIfExists('community_services.event_drafts');
    }
};
