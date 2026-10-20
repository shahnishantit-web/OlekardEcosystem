<?php

declare(strict_types=1);

// Idempotent fixtures for the isolated olekard_browser_qa database only.
// Every row carries a browser-qa-20261017 marker and uses zero-value/inert states.
use Illuminate\Support\Facades\DB;

$adminId = (string) DB::connection('identity')->table('users')
    ->where('email', 'admin@olekard.local')->value('id');
if ($adminId === '') {
    throw new RuntimeException('The isolated QA admin account was not found.');
}

$now = now();
$date = $now->toDateString();
$ids = [
    'approval' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad11',
    'config' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad12',
    'fee' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad13',
    'provider' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad14',
    'api_client' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad24',
    'health' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad15',
    'kyc' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad16',
    'qa' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad01',
    'batch' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad17',
    'settlement' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad18',
    'report' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad19',
    'report_definition' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad23',
    'alert' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad20',
    'finding' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad21',
    'support' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad22',
    'wallet' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad27',
    'agent_user' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad28',
    'agent_application' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad29',
    'agent' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad30',
];
$seeded = [];
$seed = static function (string $label, string $connection, string $table, array $keys, array $values) use (&$seeded): void {
    DB::connection($connection)->table($table)->updateOrInsert($keys, $values);
    $seeded[] = $label;
};

$seed('configuration approval', 'platform', 'platform.configuration_change_requests', ['id' => $ids['approval']], [
    'config_key' => 'operational_policy.browser_qa_detail_fixture', 'domain' => 'operational_policy', 'proposed_value' => json_encode('inert'),
    'previous_value' => json_encode('inert'), 'maker_id' => $adminId, 'status' => 'pending_approval',
    'idempotency_key' => 'browser-qa-20261017-approval', 'correlation_id' => $ids['approval'],
    'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('configuration value', 'platform', 'platform.configuration_values', ['id' => $ids['config']], [
    'config_key' => 'operational_policy.browser_qa_detail_fixture', 'config_value' => 'inert', 'value_type' => 'string',
    'status' => 'active', 'version' => 1, 'is_tbd' => false, 'description' => 'browser-qa-20261017 fixture',
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('configuration registry key', 'platform', 'platform.configurations', ['config_key' => 'operational_policy.browser_qa_detail_fixture'], [
    'id' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad25', 'domain' => 'operational_policy',
    'description' => 'browser-qa-20261017 inert detail fixture', 'created_at' => $now, 'updated_at' => $now,
]);
$seed('isolated admin profile', 'profile', 'profile.user_profiles', ['user_id' => $adminId], [
    'id' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad26', 'first_name' => 'Browser', 'last_name' => 'QA Admin',
    'email' => 'admin@olekard.local', 'mobile_number' => '+26700000000', 'account_status' => 'active',
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('isolated QA agent identity', 'identity', 'identity.users', ['id' => $ids['agent_user']], [
    'mobile_number' => '+26700000028', 'email' => 'browser-qa-agent@olekard.local', 'status' => 'active',
    'actor_type' => 'AGENT', 'created_at' => $now, 'updated_at' => $now,
]);
$seed('isolated QA agent profile', 'profile', 'profile.user_profiles', ['user_id' => $ids['agent_user']], [
    'id' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad31', 'first_name' => 'Browser QA', 'last_name' => 'Agent',
    'email' => 'browser-qa-agent@olekard.local', 'mobile_number' => '+26700000028', 'account_status' => 'active',
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('isolated QA agent application', 'platform', 'agent.agent_applications', ['id' => $ids['agent_application']], [
    'user_id' => $ids['agent_user'], 'agent_type' => 'individual', 'status' => 'approved',
    'personal_info' => json_encode(['first_name' => 'Browser QA', 'last_name' => 'Agent']),
    'correlation_id' => $ids['agent_application'], 'idempotency_key' => 'browser-qa-20261017-agent-application',
    'submitted_at' => $now, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('isolated active QA agent', 'platform', 'agent.agents', ['id' => $ids['agent']], [
    'application_id' => $ids['agent_application'], 'user_id' => $ids['agent_user'], 'agent_type' => 'individual',
    'status' => 'active', 'approved_by' => $adminId, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('inactive fee rule', 'platform', 'fees.fee_rules', ['id' => $ids['fee']], [
    'transaction_type' => 'transfer', 'fee_type' => 'fixed', 'currency' => 'BWP', 'subject_type' => 'global',
    'amount_minor' => 1, 'valid_from' => $now->copy()->subYears(2), 'valid_to' => $now->copy()->subYear(),
    'status' => 'deactivated', 'created_by' => $adminId, 'idempotency_key' => 'browser-qa-20261017-fee',
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('inactive provider', 'platform', 'integration.integrations', ['id' => $ids['provider']], [
    'provider_type' => 'bank', 'provider_slug' => 'browser-qa-provider', 'display_name' => 'Browser QA Provider',
    'credentials_reference' => 'qa://browser-qa-20261017/no-credentials', 'status' => 'inactive',
    'is_default' => false, 'configuration' => json_encode(['qa_fixture' => true]),
    'created_by_user_id' => $adminId, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('inactive API client', 'platform', 'integration.api_clients', ['id' => $ids['api_client']], [
    'name' => 'Browser QA API client', 'api_key' => 'bqa_20261017_inert_fixture_key',
    'secret_hash' => password_hash('browser-qa-inert-secret', PASSWORD_BCRYPT), 'status' => 'inactive',
    'permissions' => json_encode([]), 'rate_limit_per_minute' => 1, 'last_used_at' => null,
    'created_by_user_id' => $adminId, 'integration_id' => $ids['provider'], 'ip_whitelist' => json_encode([]),
    'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('provider health sample', 'platform', 'ops.provider_health_checks', ['id' => $ids['health']], [
    'provider_type' => 'bank', 'status' => 'degraded', 'response_time_ms' => null,
    'error_message' => 'browser-qa-20261017 fixture; no provider call made', 'checked_at' => $now,
    'created_at' => $now, 'integration_id' => $ids['provider'],
]);
$seed('synthetic KYC case', 'kyc', 'kyc.kyc_cases', ['id' => $ids['kyc']], [
    'user_id' => $adminId, 'status' => 'under_review', 'attempt_number' => 1,
    'submitted_at' => $now, 'idempotency_key' => 'browser-qa-20261017-kyc', 'created_at' => $now, 'updated_at' => $now,
]);
$seed('closed zero-balance wallet', 'wallet', 'wallet.wallets', ['id' => $ids['wallet']], [
    'wallet_reference' => 'BQA-20261017-DETAIL', 'owner_id' => $adminId, 'status' => 'closed',
    'idempotency_key' => 'browser-qa-20261017-wallet', 'version' => 0, 'created_by' => $adminId,
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('QA run', 'platform', 'qa.test_runs', ['id' => $ids['qa']], [
    'category' => 'regression', 'triggered_by' => $adminId, 'status' => 'completed',
    'idempotency_key' => 'browser-qa-20261017-detail-run', 'correlation_id' => 'd026a4eb-0e40-4fa7-a67b-d815f021ad02',
    'started_at' => $now->copy()->subMinute(), 'completed_at' => $now, 'created_at' => $now,
]);
$seed('zero-value reconciliation batch', 'reconciliation', 'reconciliation.reconciliation_batches', ['id' => $ids['batch']], [
    'idempotency_key' => 'browser-qa-20261017-batch', 'source_type' => 'provider_file',
    'source_reference' => 'browser-qa-20261017-empty', 'batch_date' => $date, 'provider_code' => 'QA',
    'status' => 'completed', 'submitted_by' => $adminId, 'metadata' => json_encode(['qa_fixture' => true]),
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('zero-value settlement', 'reconciliation', 'reconciliation.settlement_batches', ['id' => $ids['settlement']], [
    'idempotency_key' => 'browser-qa-20261017-settlement', 'settlement_type' => 'provider_settlement',
    'provider_code' => 'QA', 'period_start' => $date, 'period_end' => $date, 'currency_code' => 'BWP',
    'gross_amount' => 0, 'fee_amount' => 0, 'net_amount' => 0, 'status' => 'draft', 'maker_id' => $adminId,
    'metadata' => json_encode(['qa_fixture' => true]), 'created_at' => $now, 'updated_at' => $now,
]);
$seed('QA report definition', 'platform', 'reporting.report_definitions', ['id' => $ids['report_definition']], [
    'type' => 'browser_qa_report', 'name' => 'Browser QA report fixture',
    'description' => 'Synthetic zero-row report for isolated browser testing.', 'required_permission' => 'reports.read',
    'pii_sensitive' => false, 'filter_schema' => json_encode((object) []), 'default_max_rows' => 0,
    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('completed zero-row report job', 'platform', 'reporting.report_jobs', ['id' => $ids['report']], [
    'definition_id' => $ids['report_definition'], 'report_type' => 'browser_qa_report',
    'requested_by_user_id' => $adminId, 'correlation_id' => $ids['report'], 'filters' => json_encode((object) []),
    'as_of_at' => $now, 'status' => 'complete', 'pii_access' => false, 'row_count' => 0,
    'result_data' => json_encode(['qa_fixture' => true, 'rows' => []]), 'created_at' => $now,
    'started_at' => $now, 'completed_at' => $now,
]);
$seed('open support ticket', 'platform', 'support.support_tickets', ['id' => $ids['support']], [
    'ticket_number' => 'BQA-20261017-001', 'subject' => 'Browser QA detail fixture',
    'description' => 'Isolated browser test record; no customer action required.', 'category' => 'general',
    'priority' => 'low', 'status' => 'open', 'reporter_id' => $adminId, 'reporter_type' => 'admin',
    'idempotency_key' => 'browser-qa-20261017-support', 'correlation_id' => $ids['support'],
    'created_at' => $now, 'updated_at' => $now,
]);
$seed('open synthetic security alert', 'risk', 'risk.risk_alerts', ['id' => $ids['alert']], [
    'alert_type' => 'pattern', 'status' => 'open', 'severity' => 'low', 'subject_type' => 'qa_fixture',
    'subject_id' => $ids['alert'], 'title' => 'Browser QA alert fixture',
    'description' => 'Synthetic isolated test alert; no investigation action required.',
    'correlation_id' => $ids['alert'], 'sla_due_at' => $now->copy()->addDay(),
    'created_by' => $adminId, 'updated_by' => $adminId, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
]);
$seed('open synthetic security finding', 'platform', 'security.vulnerability_findings', ['id' => $ids['finding']], [
    'scanner' => 'browser-qa', 'finding_ref' => 'browser-qa-20261017-finding', 'severity' => 'info',
    'component' => 'QA fixture only', 'title' => 'Browser QA finding fixture',
    'description' => 'Synthetic isolated test finding.', 'status' => 'open', 'detected_at' => $now,
    'created_at' => $now, 'updated_at' => $now,
]);

echo json_encode(['database' => DB::connection('platform')->getDatabaseName(), 'marker' => 'browser-qa-20261017', 'ids' => $ids, 'seeded' => $seeded], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
