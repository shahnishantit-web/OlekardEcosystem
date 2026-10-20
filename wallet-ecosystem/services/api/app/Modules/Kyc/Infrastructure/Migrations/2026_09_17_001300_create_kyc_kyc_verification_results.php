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
        Schema::connection('kyc')->create('kyc.kyc_verification_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('kyc_case_id');
            $table->string('provider_name', 100);
            $table->string('provider_reference', 255)->nullable();
            $table->string('outcome', 30);
            $table->decimal('confidence_score', 5, 4)->nullable();
            // SEC-E05-003: raw provider response MUST NOT be stored; only HMAC-SHA256 hash
            $table->string('raw_response_hash', 64);
            $table->timestampTz('created_at');
        });

        DB::connection('kyc')->statement(
            'CREATE INDEX kyc_verification_results_case_created_idx ON kyc.kyc_verification_results (kyc_case_id, created_at DESC)'
        );

        DB::connection('kyc')->statement(
            'ALTER TABLE kyc.kyc_verification_results ADD CONSTRAINT kyc_verification_results_case_id_fk FOREIGN KEY (kyc_case_id) REFERENCES kyc.kyc_cases(id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::connection('kyc')->dropIfExists('kyc.kyc_verification_results');
    }
};
