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
        Schema::connection('platform')->create('reporting.schedule_runs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('schedule_id');
            $table->uuid('job_id')->nullable();
            $table->string('outcome', 32);
            $table->text('failure_reason')->nullable();
            $table->timestampTz('triggered_at')->useCurrent();

            $table->foreign('schedule_id')
                ->references('id')
                ->on('reporting.report_schedules')
                ->cascadeOnDelete();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX schedule_runs_schedule_triggered_idx ON reporting.schedule_runs (schedule_id, triggered_at DESC)'
        );

        DB::connection('platform')->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reporting.prevent_schedule_run_mutation()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'schedule_run_immutable';
            END;
            $$
        SQL);

        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER schedule_runs_immutable_update
                BEFORE UPDATE ON reporting.schedule_runs
                FOR EACH ROW EXECUTE FUNCTION reporting.prevent_schedule_run_mutation()
        SQL);

        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER schedule_runs_immutable_delete
                BEFORE DELETE ON reporting.schedule_runs
                FOR EACH ROW EXECUTE FUNCTION reporting.prevent_schedule_run_mutation()
        SQL);
    }

    public function down(): void
    {
        DB::connection('platform')->statement(
            'DROP TRIGGER IF EXISTS schedule_runs_immutable_update ON reporting.schedule_runs'
        );
        DB::connection('platform')->statement(
            'DROP TRIGGER IF EXISTS schedule_runs_immutable_delete ON reporting.schedule_runs'
        );
        DB::connection('platform')->statement(
            'DROP FUNCTION IF EXISTS reporting.prevent_schedule_run_mutation()'
        );
        Schema::connection('platform')->dropIfExists('reporting.schedule_runs');
    }
};
