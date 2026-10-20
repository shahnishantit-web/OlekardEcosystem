<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Idempotent, explicitly synthetic local records; no posting, delivery or provider calls. */
final class DevAdminDemoDataSeeder extends Seeder
{
    private function id(string $name): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/admin-demo/'.$name);
    }

    private function insert(string $table, string $name, array $values): string
    {
        $id = $this->id($name);
        if (! DB::connection('platform')->table($table)->where('id', $id)->exists()) {
            DB::connection('platform')->table($table)->insert(['id' => $id] + $values + ['created_at' => now(), 'updated_at' => now()]);
        }
        return $id;
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevAdminDemoDataSeeder only runs in local/testing.');
            return;
        }
        $db = DB::connection('platform');
        $admin = $db->table('identity.users')->where('email', 'admin@olekard.local')->value('id');
        $wallet = $db->table('wallet.wallets')->where('status', 'active')->first();
        if (! $admin || ! $wallet) throw new \RuntimeException('Local admin and an existing wallet are required.');
        $customer = $wallet->owner_id;

        $db->transaction(function () use ($db, $admin, $wallet, $customer): void {
            // The admin key browser needs registry metadata as well as values.
            // Preserve existing policies, including every unresolved TBD value.
            foreach ($db->table('platform.configuration_values')->where('status', 'active')->get() as $value) {
                if (! $db->table('platform.configurations')->where('config_key', $value->config_key)->exists()) {
                    $this->insert('platform.configurations', 'config-'.$value->config_key, [
                        'config_key' => $value->config_key, 'config_type' => $value->value_type,
                        'is_tbd' => $value->is_tbd, 'domain' => explode('.', $value->config_key)[0],
                        'description' => $value->description ?? 'Existing application configuration key',
                    ]);
                }
            }
            if (! $db->table('profile.user_profiles')->where('user_id', $admin)->exists()) {
                $this->insert('profile.user_profiles', 'admin-profile', [
                    'user_id' => $admin, 'first_name' => 'Local', 'last_name' => 'Administrator',
                    'email' => 'admin@olekard.local', 'preferred_language' => 'en', 'account_status' => 'active',
                ]);
            }
            $this->insert('kyc.kyc_cases', 'kyc-review', [
                'user_id' => $customer, 'status' => 'under_review', 'attempt_number' => 1,
                'submitted_at' => now(), 'idempotency_key' => 'admin-demo:kyc-review',
                'info_request_note' => 'LOCAL DEMO: synthetic case for admin page review; no identity documents attached.',
            ]);
            $this->insert('transaction.transaction_projections', 'pending-transfer', [
                'reference' => 'DEMO-ADMIN-PENDING-TRANSFER', 'type' => 'transfer', 'status' => 'pending',
                'amount' => 1000, 'currency_code' => 'BWP', 'direction' => 'debit',
                'wallet_id' => $wallet->id, 'customer_id' => $customer,
                'description' => 'LOCAL DEMO: pending projection only; no funds moved',
                'source_module' => 'transfer', 'metadata' => json_encode(['demo' => true]),
                'idempotency_key' => 'admin-demo:pending-transfer', 'correlation_id' => $this->id('correlation'),
            ]);
            $this->insert('risk.risk_alerts', 'risk-alert', [
                'alert_type' => 'auth_failure', 'severity' => 'low', 'status' => 'open',
                'subject_type' => 'customer', 'subject_id' => $customer,
                'title' => 'LOCAL DEMO: authentication review',
                'description' => 'Synthetic training alert; no actual suspicious activity is asserted.',
                'sla_due_at' => now()->addDays(7), 'created_by' => $admin, 'updated_by' => $admin,
                'correlation_id' => $this->id('correlation'),
            ]);
            $this->insert('security.vulnerability_findings', 'security-finding', [
                'scanner' => 'local-demo', 'finding_ref' => 'DEMO-001', 'severity' => 'info',
                'component' => 'local training environment', 'title' => 'LOCAL DEMO: security review example',
                'description' => 'Synthetic finding for interface review; not a scan result.',
                'status' => 'open', 'detected_at' => now(),
                'remediation_steps' => 'Review the example; no production remediation is required.',
            ]);
            $ticket = $this->insert('support.support_tickets', 'support-ticket', [
                'ticket_number' => 'DEMO-ADMIN-001', 'subject' => 'LOCAL DEMO: account assistance',
                'description' => 'Synthetic support case for the local administration pages.',
                'category' => 'general', 'priority' => 'low', 'status' => 'open',
                'reporter_id' => $customer, 'reporter_type' => 'customer', 'assignee_id' => $admin,
                'idempotency_key' => 'admin-demo:support-ticket', 'correlation_id' => $this->id('correlation'),
            ]);
            if (! $db->table('support.admin_cases')->where('case_id', $ticket)->exists()) {
                $db->table('support.admin_cases')->insert(['case_id' => $ticket, 'reason' => 'LOCAL DEMO']);
            }
            $this->insert('reconciliation.reconciliation_batches', 'reconciliation-draft', [
                'idempotency_key' => 'admin-demo:reconciliation-draft', 'source_type' => 'bank_statement',
                'source_reference' => 'LOCAL-DEMO-NO-STATEMENT', 'batch_date' => now()->toDateString(),
                'status' => 'draft', 'submitted_by' => $admin, 'metadata' => json_encode(['demo' => true]),
            ]);
            $this->insert('reconciliation.settlement_batches', 'settlement-draft', [
                'idempotency_key' => 'admin-demo:settlement-draft', 'settlement_type' => 'bank_settlement',
                'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
                'currency_code' => 'BWP', 'status' => 'draft', 'maker_id' => $admin,
                'metadata' => json_encode(['demo' => true, 'description' => 'LOCAL DEMO: empty unexecuted draft']),
            ]);
            $this->insert('reporting.report_schedules', 'report-schedule', [
                'owner_user_id' => $admin, 'name' => 'LOCAL DEMO: weekly transaction report (paused)',
                'report_type' => 'transaction-report', 'cadence' => 'weekly', 'cadence_time' => '09:00',
                'cadence_day_of_week' => 1, 'status' => 'paused', 'recipients' => '[]',
                'filters' => '{}', 'email_notification_enabled' => false,
            ]);
            $this->insert('ops.alert_rules', 'ops-rule', [
                'name' => 'LOCAL DEMO: disabled error-rate example', 'description' => 'Synthetic rule; notifications disabled.',
                'metric_condition' => json_encode(['metric' => 'http.error_rate', 'operator' => '>', 'threshold' => 5]),
                'severity' => 'warning', 'notification_channels' => '[]', 'is_enabled' => false, 'created_by' => $admin,
            ]);
            $this->insert('integration.integrations', 'inactive-integration', [
                'provider_type' => 'sms', 'provider_slug' => 'local-demo',
                'display_name' => 'LOCAL DEMO: inactive integration (no provider configured)',
                'credentials_reference' => 'local-demo/unconfigured', 'status' => 'inactive',
                'is_default' => false, 'configuration' => json_encode(['demo' => true]),
                'created_by_user_id' => $admin,
            ]);
            $notification = $this->insert('notification.notifications', 'unsent-notification', [
                'source_event_id' => $this->id('notification-source'), 'source_event_type' => 'local.demo',
                'notification_type' => 'local-demo', 'category' => 'operational', 'mandatory' => false,
                'recipient_id' => $admin, 'subject' => 'LOCAL DEMO: notification log example',
                'body' => 'Synthetic log record; no delivery was attempted or queued.',
                'status' => 'failed', 'correlation_id' => $this->id('correlation'), 'rule_channels' => '[]',
            ]);
            $this->insert('notification.notification_deliveries', 'unsent-delivery', [
                'notification_id' => $notification, 'channel' => 'email', 'status' => 'permanently_failed',
                'attempt_count' => 0, 'error_code' => 'LOCAL_DEMO_NO_DELIVERY',
                'error_message' => 'Synthetic example; no message was sent.',
            ]);
            // A dedicated inactive template keeps demo rules separate from all
            // existing delivery and mandatory-notification policies.
            $template = $this->insert('notification.notification_templates', 'inactive-template', [
                'key' => 'local-demo', 'category' => 'operational', 'language' => 'en',
                'subject_template' => 'LOCAL DEMO: unsent notification',
                'body_template' => 'Synthetic example only. No delivery is configured.',
                'supported_channels' => '["email"]', 'variables' => '[]', 'active' => false, 'revision' => 1,
            ]);
            if (! $db->table('notification.admin_rules')->where('template_id', $template)->where('channel', 'email')->exists()) {
                $db->table('notification.admin_rules')->insert([
                    'template_id' => $template, 'channel' => 'email', 'enabled' => false,
                    'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        $this->call(ReportDefinitionSeeder::class);
        $this->call(\App\Modules\Qa\Infrastructure\Seeders\TestCaseSeeder::class);
        $this->command?->info('Local demo records added; financial balances and journal history unchanged.');
    }
}
