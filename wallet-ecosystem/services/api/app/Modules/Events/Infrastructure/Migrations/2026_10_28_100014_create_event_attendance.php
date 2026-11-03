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
        Schema::connection('community_services')->create('community_services.event_attendance', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id')->unique();
            $table->uuid('event_id')->index();
            $table->uuid('organisation_id')->index();
            $table->uuid('gate_id')->index();
            $table->uuid('operator_actor_id')->index();
            $table->uuid('category_id');
            $table->string('category_name', 120);
            $table->string('admission_state', 24)->default('admitted');
            $table->timestampTz('admitted_at');
            $table->timestampsTz();
            $table->foreign('ticket_id')->references('id')->on('community_services.event_tickets')->restrictOnDelete();
            $table->foreign(['gate_id', 'event_id', 'organisation_id'])
                ->references(['id', 'event_id', 'organisation_id'])->on('community_services.event_gates')->restrictOnDelete();
            $table->unique(['id', 'event_id', 'organisation_id'], 'event_attendance_scope_unique');
        });

        Schema::connection('community_services')->create('community_services.event_attendance_audit', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('attendance_id');
            $table->uuid('ticket_id');
            $table->uuid('event_id');
            $table->uuid('organisation_id');
            $table->uuid('gate_id');
            $table->uuid('operator_actor_id');
            $table->uuid('category_id');
            $table->string('action', 24);
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at');
            $table->foreign('attendance_id')->references('id')->on('community_services.event_attendance')->restrictOnDelete();
            $table->index(['event_id', 'created_at'], 'event_attendance_audit_event_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('community_services')->dropIfExists('community_services.event_attendance_audit');
        Schema::connection('community_services')->dropIfExists('community_services.event_attendance');
    }
};
