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
        Schema::connection('kyc')->create('kyc.kyc_personal_info', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('kyc_case_id')->unique();
            $table->string('full_legal_name', 255);
            $table->date('date_of_birth');
            $table->char('nationality', 2);
            $table->string('national_id_type', 30);
            // SEC-E05-001: national_id_number NEVER stored — only the HMAC-SHA256 hash
            $table->string('national_id_hash', 64);
            $table->string('address_line_1', 255);
            $table->string('address_line_2', 255)->nullable();
            $table->string('city', 100);
            $table->string('state_province', 100)->nullable();
            $table->string('postal_code', 20);
            $table->char('country_code', 2);
            $table->timestampsTz();
        });

        // Duplicate detection: non-unique index for fast lookups; uniqueness enforced at application layer
        // A DB unique constraint would incorrectly block legitimate resubmissions by the same person
        DB::connection('kyc')->statement(
            'CREATE INDEX kyc_personal_info_national_id_hash_idx ON kyc.kyc_personal_info (national_id_hash)'
        );

        DB::connection('kyc')->statement(
            'ALTER TABLE kyc.kyc_personal_info ADD CONSTRAINT kyc_personal_info_case_id_fk FOREIGN KEY (kyc_case_id) REFERENCES kyc.kyc_cases(id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::connection('kyc')->dropIfExists('kyc.kyc_personal_info');
    }
};
