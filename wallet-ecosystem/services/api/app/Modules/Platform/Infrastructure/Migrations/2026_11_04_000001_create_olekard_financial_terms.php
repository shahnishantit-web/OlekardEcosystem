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
        $schema = Schema::connection('platform');
        if (! $schema->hasTable('platform.olekard_financial_terms_versions')) {
            $schema->create('platform.olekard_financial_terms_versions', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedInteger('version')->unique();
                $table->jsonb('content');
                $table->char('content_hash', 64);
                $table->timestampTz('effective_from');
                $table->timestampTz('effective_until')->nullable();
                $table->string('status', 20)->default('draft');
                $table->boolean('test_only')->default(false);
                $table->uuid('created_by_actor_id');
                $table->uuid('approved_by_actor_id')->nullable();
                $table->string('approval_reference', 255)->nullable();
                $table->string('approval_evidence_provenance', 255)->nullable();
                $table->unsignedInteger('approval_terms_version')->nullable();
                $table->char('approval_content_hash', 64)->nullable();
                $table->timestampTz('approved_at')->nullable();
                $table->uuid('published_by_actor_id')->nullable();
                $table->timestampTz('published_at')->nullable();
                $table->string('idempotency_key', 128)->unique();
                $table->string('correlation_id', 64);
                $table->timestampsTz();
                $table->index(['status', 'effective_from', 'effective_until'], 'olekard_terms_current_idx');
            });
        }
        if (! $schema->hasTable('platform.olekard_financial_terms_acceptances')) {
            $schema->create('platform.olekard_financial_terms_acceptances', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('terms_version_id');
                $table->uuid('organisation_id');
                $table->uuid('event_id');
                $table->uuid('accepted_by_actor_id');
                $table->string('representative_verification_id', 100);
                $table->unsignedInteger('representative_verification_version');
                $table->string('representative_evidence_reference', 255);
                $table->timestampTz('representative_verified_at');
                $table->timestampTz('representative_valid_until');
                $table->timestampTz('accepted_at');
                $table->char('accepted_content_hash', 64);
                $table->string('idempotency_key', 128);
                $table->string('correlation_id', 64);
                $table->timestampsTz();
                $table->unique(['terms_version_id', 'organisation_id', 'event_id'], 'olekard_terms_acceptance_scope_uq');
                $table->unique(['organisation_id', 'event_id', 'idempotency_key'], 'olekard_terms_acceptance_idem_uq');
                $table->foreign('terms_version_id')->references('id')->on('platform.olekard_financial_terms_versions')->restrictOnDelete();
                $table->index(['organisation_id', 'event_id', 'terms_version_id'], 'olekard_terms_acceptance_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('platform.olekard_financial_terms_acceptances');
        Schema::connection('platform')->dropIfExists('platform.olekard_financial_terms_versions');
    }
};
