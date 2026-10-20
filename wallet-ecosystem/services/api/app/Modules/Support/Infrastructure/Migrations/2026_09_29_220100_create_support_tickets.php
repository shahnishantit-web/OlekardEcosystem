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
        Schema::connection('support')->create('support.support_tickets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('ticket_number', 20)->unique()->notNull();
            $table->string('subject', 255)->notNull();
            $table->text('description')->notNull();
            $table->string('category', 30)->notNull();
            $table->string('priority', 20)->notNull()->default('medium');
            $table->string('status', 30)->notNull()->default('open');
            $table->uuid('reporter_id')->notNull();
            $table->string('reporter_type', 20)->notNull();
            $table->uuid('assignee_id')->nullable();
            $table->uuid('dispute_id')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('idempotency_key', 255)->unique()->nullable();
            $table->string('correlation_id', 100)->notNull();
            $table->timestampsTz();
        });

        DB::connection('support')->statement(
            'CREATE INDEX idx_support_tickets_reporter ON support.support_tickets (reporter_id, reporter_type)'
        );
        DB::connection('support')->statement(
            'CREATE INDEX idx_support_tickets_status ON support.support_tickets (status)'
        );
        DB::connection('support')->statement(
            'CREATE INDEX idx_support_tickets_assignee ON support.support_tickets (assignee_id) WHERE assignee_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::connection('support')->dropIfExists('support.support_tickets');
    }
};
