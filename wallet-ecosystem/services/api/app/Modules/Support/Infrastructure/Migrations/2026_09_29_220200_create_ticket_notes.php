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
        Schema::connection('support')->create('support.ticket_notes', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->notNull();
            $table->uuid('author_id')->notNull();
            $table->string('author_type', 20)->notNull();
            $table->text('content')->notNull();
            $table->boolean('is_internal')->notNull()->default(false);
            $table->timestampTz('created_at')->notNull();

            $table->foreign('ticket_id')
                ->references('id')
                ->on('support.support_tickets')
                ->onDelete('cascade');
        });

        DB::connection('support')->statement(
            'CREATE INDEX idx_ticket_notes_ticket_id ON support.ticket_notes (ticket_id, created_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('support')->dropIfExists('support.ticket_notes');
    }
};
