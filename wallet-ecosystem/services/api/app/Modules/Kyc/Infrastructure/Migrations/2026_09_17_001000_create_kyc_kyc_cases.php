<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'kyc';

    public function up(): void
    {
        DB::connection('kyc')->statement('CREATE SCHEMA IF NOT EXISTS kyc');

        Schema::connection('kyc')->create('kyc.kyc_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('status', 30)->default('initiated');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('reviewer_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('info_request_note')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestampsTz();
        });

        // Open case check per BR-E05-001
        DB::connection('kyc')->statement(
            'CREATE INDEX kyc_cases_user_id_status_idx ON kyc.kyc_cases (user_id, status)'
        );

        // Expiry job: fetch approved cases past expires_at
        DB::connection('kyc')->statement(
            "CREATE INDEX kyc_cases_status_expires_at_idx ON kyc.kyc_cases (status, expires_at) WHERE status = 'approved'"
        );

        // Idempotency replay
        DB::connection('kyc')->statement(
            'CREATE UNIQUE INDEX kyc_cases_idempotency_key_unique ON kyc.kyc_cases (idempotency_key) WHERE idempotency_key IS NOT NULL'
        );

        // FK: user_id → identity.users.id (cross-schema; identity is on a different connection so raw SQL)
        DB::connection('kyc')->statement(
            'ALTER TABLE kyc.kyc_cases ADD CONSTRAINT kyc_cases_user_id_fk FOREIGN KEY (user_id) REFERENCES identity.users(id)'
        );
        DB::connection('kyc')->statement(
            'ALTER TABLE kyc.kyc_cases ADD CONSTRAINT kyc_cases_reviewer_id_fk FOREIGN KEY (reviewer_id) REFERENCES identity.users(id)'
        );
    }

    public function down(): void
    {
        Schema::connection('kyc')->dropIfExists('kyc.kyc_cases');
    }
};
