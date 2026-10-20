<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E26 T025 — Add `document_number` and `extracted_data` encrypted fields to
 * kyc.kyc_documents. Stored as AES-256-CBC ciphertext via Laravel `encrypted` cast.
 */
return new class extends Migration
{
    protected $connection = 'kyc';

    public function up(): void
    {
        Schema::connection($this->connection)->table('kyc.kyc_documents', function (Blueprint $table): void {
            $table->text('document_number')->nullable()->after('document_type');
            $table->text('extracted_data')->nullable()->after('document_number');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('kyc.kyc_documents', function (Blueprint $table): void {
            $table->dropColumn(['document_number', 'extracted_data']);
        });
    }
};
