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
        Schema::connection('platform')->create('agent.agent_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->string('document_type', 40);
            $table->text('storage_reference')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 30)->default('pending_review');
            $table->string('rejection_reason', 100)->nullable();
            $table->timestampTz('uploaded_at')->default(DB::raw('now()'));
        });

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agent_documents ADD CONSTRAINT ad_document_type_check CHECK (document_type IN ('national_id','proof_of_address','business_registration'))"
        );

        DB::connection('platform')->statement(
            "ALTER TABLE agent.agent_documents ADD CONSTRAINT ad_status_check CHECK (status IN ('pending_review','accepted','rejected','quarantined'))"
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agent_documents ADD CONSTRAINT ad_size_bytes_check CHECK (size_bytes > 0)'
        );

        DB::connection('platform')->statement(
            'ALTER TABLE agent.agent_documents ADD CONSTRAINT ad_application_id_fk FOREIGN KEY (application_id) REFERENCES agent.agent_applications(id) ON DELETE CASCADE'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX ad_application_id_document_type_idx ON agent.agent_documents (application_id, document_type)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX ad_application_id_status_idx ON agent.agent_documents (application_id, status)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('agent.agent_documents');
    }
};
