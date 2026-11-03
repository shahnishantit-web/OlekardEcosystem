<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'community_services';

    public function up(): void
    {
        Schema::connection('community_services')->create('community_services.event_ticket_security_audit', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('actor_id');
            $table->string('action', 32);
            $table->string('idempotency_key', 128);
            $table->timestampTz('created_at');
            $table->foreign('ticket_id')->references('id')->on('community_services.event_tickets')->restrictOnDelete();
            $table->unique(['ticket_id', 'idempotency_key'], 'event_ticket_security_audit_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_ticket_security_audit');
    }
};
