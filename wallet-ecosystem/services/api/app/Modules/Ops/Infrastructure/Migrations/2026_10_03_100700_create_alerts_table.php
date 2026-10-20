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
        Schema::connection('platform')->create('ops.alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('alert_rule_id');
            $table->string('status', 20)->default('firing');
            $table->text('condition_summary');
            $table->timestampTz('fired_at')->default(DB::raw('now()'));
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('notification_sent_at')->nullable();
            $table->timestampTz('resolution_notification_sent_at')->nullable();
            $table->timestampsTz();
        });

        DB::connection('platform')->statement(
            'CREATE INDEX idx_alerts_rule_status ON ops.alerts (alert_rule_id, status)'
        );
        DB::connection('platform')->statement(
            'CREATE INDEX idx_alerts_fired_at ON ops.alerts (fired_at DESC)'
        );

        DB::connection('platform')->statement(
            "ALTER TABLE ops.alerts
             ADD CONSTRAINT fk_alerts_rule FOREIGN KEY (alert_rule_id) REFERENCES ops.alert_rules(id)"
        );
    }

    public function down(): void
    {
        Schema::connection('platform')->dropIfExists('ops.alerts');
    }
};
