<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    protected $connection = 'notification';

    public function up(): void
    {
        foreach ([['support.dispute_refunded', 'Dispute refund completed', 'Your dispute {{case_id}} has been refunded: {{amount_minor}} minor units {{currency_code}}.'], ['support.case_escalated', 'Support case escalated', 'Case {{case_id}} has been assigned to you for senior review. The escalation SLA is 24 hours.']] as [$key,$subject,$body]) {
            DB::connection('notification')->table('notification.notification_templates')->insertOrIgnore(['id' => (string) Uuid::uuid7(), 'key' => $key, 'category' => 'financial', 'subject_template' => $subject, 'body_template' => $body, 'supported_channels' => json_encode(['in_app', 'email']), 'variables' => json_encode(['case_id', 'amount_minor', 'currency_code', 'occurred_at']), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
