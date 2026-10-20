<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'platform';

    public function up(): void
    {
        Schema::connection('platform')->create('reporting.report_schedules', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_user_id');
            $table->string('name', 255);
            $table->string('report_type', 64);
            $table->jsonb('filters')->default('{}');
            $table->string('cadence', 32);
            $table->string('cadence_time', 8);
            $table->unsignedTinyInteger('cadence_day_of_week')->nullable();
            $table->unsignedTinyInteger('cadence_day_of_month')->nullable();
            $table->string('status', 16)->default('active');
            $table->jsonb('recipients')->default('[]');
            $table->boolean('email_notification_enabled')->default(false);
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
        });

        DB::connection('platform')->statement(
            'ALTER TABLE reporting.report_schedules ADD CONSTRAINT report_schedules_cadence_dom_check CHECK (cadence_day_of_month BETWEEN 1 AND 28)'
        );

        DB::connection('platform')->statement(
            'CREATE INDEX report_schedules_status_next_run_idx ON reporting.report_schedules (status, next_run_at)'
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('reporting.report_schedules');
    }
};
