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
        $schema = Schema::connection('community_services');
        if (! $schema->hasColumn('community_services.event_drafts', 'hold_duration_seconds')) {
            $schema->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->unsignedInteger('hold_duration_seconds')->nullable();
            });
        }
        if (! $schema->hasColumn('community_services.event_draft_categories', 'issued_quantity')) {
            $schema->table('community_services.event_draft_categories', static function (Blueprint $table): void {
                $table->unsignedInteger('issued_quantity')->default(0);
            });
        }
        if (! $schema->hasTable('community_services.event_inventory_holds')) {
            $schema->create('community_services.event_inventory_holds', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id')->index();
                $table->uuid('category_id')->index();
                $table->string('category_name', 120);
                $table->uuid('organisation_id')->index();
                $table->uuid('customer_actor_id')->index();
                $table->unsignedInteger('quantity');
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->string('status', 16)->default('held');
                $table->timestampTz('expires_at')->index();
                $table->timestampsTz();
                $table->unique(['event_id', 'customer_actor_id', 'idempotency_key'], 'event_hold_idempotency_uq');
                $table->index(['event_id', 'category_id', 'status', 'expires_at'], 'event_hold_capacity_idx');
                $table->foreign('event_id')->references('id')->on('community_services.event_drafts')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('community_services');
        $schema->dropIfExists('community_services.event_inventory_holds');
        if ($schema->hasColumn('community_services.event_draft_categories', 'issued_quantity')) {
            $schema->table('community_services.event_draft_categories', static function (Blueprint $table): void {
                $table->dropColumn('issued_quantity');
            });
        }
        if ($schema->hasColumn('community_services.event_drafts', 'hold_duration_seconds')) {
            $schema->table('community_services.event_drafts', static function (Blueprint $table): void {
                $table->dropColumn('hold_duration_seconds');
            });
        }
    }
};
