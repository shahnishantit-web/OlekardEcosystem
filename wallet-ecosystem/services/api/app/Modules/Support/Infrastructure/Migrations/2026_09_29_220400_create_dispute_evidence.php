<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'support';

    public function up(): void
    {
        Schema::connection('support')->create('support.dispute_evidence', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('dispute_id')->notNull();
            $table->uuid('uploaded_by')->notNull();
            $table->string('file_key', 500)->notNull();
            $table->string('file_type', 20)->notNull();
            $table->string('original_name', 255)->notNull();
            $table->string('label', 255)->nullable();
            $table->bigInteger('size_bytes')->notNull();
            $table->string('idempotency_key', 255)->unique()->nullable();
            $table->timestampTz('created_at')->notNull();

            $table->foreign('dispute_id')
                ->references('id')
                ->on('support.disputes')
                ->onDelete('cascade');
        });

        DB::connection('support')->statement(
            'ALTER TABLE support.dispute_evidence ADD CONSTRAINT chk_evidence_size CHECK (size_bytes <= 10485760)'
        );

        DB::connection('support')->statement(
            'CREATE INDEX idx_dispute_evidence_dispute_id ON support.dispute_evidence (dispute_id, created_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('support')->dropIfExists('support.dispute_evidence');
    }
};
