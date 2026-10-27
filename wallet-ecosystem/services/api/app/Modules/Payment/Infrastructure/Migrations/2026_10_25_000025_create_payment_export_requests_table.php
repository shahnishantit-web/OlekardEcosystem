<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'payment';

    public function up(): void
    {
        Schema::connection('payment')->create('payment_export_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('requestor_id', 100);
            $table->string('scope_type', 30);
            $table->string('organisation_id', 100)->nullable();
            $table->jsonb('filters')->default('{}');
            $table->string('status', 30)->default('queued');
            $table->char('token', 64)->unique()->nullable();
            $table->integer('row_count')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();
        });

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_export_requests
             ADD CONSTRAINT per_scope_type_check
             CHECK (scope_type IN ('organisation', 'platform'))"
        );

        DB::connection('payment')->statement(
            "ALTER TABLE payment.payment_export_requests
             ADD CONSTRAINT per_status_check
             CHECK (status IN ('queued', 'generating', 'ready', 'failed', 'expired'))"
        );

        DB::connection('payment')->statement(
            'CREATE INDEX per_requestor_created_idx
             ON payment.payment_export_requests (requestor_id, created_at)'
        );

        DB::connection('payment')->statement(
            'CREATE INDEX per_organisation_created_idx
             ON payment.payment_export_requests (organisation_id, created_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('payment')->dropIfExists('payment_export_requests');
    }
};
