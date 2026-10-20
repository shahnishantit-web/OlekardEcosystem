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
        Schema::connection('kyc')->create('kyc.kyc_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('kyc_case_id');
            $table->string('document_type', 30);
            // file_reference is an opaque storage key — NEVER a file-system path
            $table->string('file_reference', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size_bytes');
            $table->date('expires_on')->nullable();
            $table->string('validation_status', 20)->default('pending');
            $table->string('validation_error', 255)->nullable();
            // SoftDeletes: used for document supersession, not true deletion
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at');
        });

        // Active documents per case per type (partial index for performance)
        DB::connection('kyc')->statement(
            'CREATE INDEX kyc_documents_case_type_active_idx ON kyc.kyc_documents (kyc_case_id, document_type) WHERE deleted_at IS NULL'
        );

        DB::connection('kyc')->statement(
            'ALTER TABLE kyc.kyc_documents ADD CONSTRAINT kyc_documents_case_id_fk FOREIGN KEY (kyc_case_id) REFERENCES kyc.kyc_cases(id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::connection('kyc')->dropIfExists('kyc.kyc_documents');
    }
};
