<?php

declare(strict_types=1);

// Package-only local schema audit/adoption. Never applies financial DDL or seeds.
$api = dirname(__DIR__, 2).'/wallet-ecosystem/services/api';
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection('payment');
if (! app()->environment('local') || ! in_array(config('database.connections.payment.host'), ['127.0.0.1', 'localhost', '::1'], true)
    || config('payment.live_callback_processing_enabled', false) !== false) {
    throw new RuntimeException('Local loopback environment with live processing disabled required.');
}
$files = glob($api.'/app/Modules/Payment/Infrastructure/Migrations/*.php');
$files[] = $api.'/database/migrations/2026_10_21_000001_p06_payment_attempt_state_engine.php';
$files[] = $api.'/app/Modules/Integration/Infrastructure/Migrations/2026_10_22_000001_add_verified_webhook_receipts.php';
sort($files);
$columns = $db->select("SELECT table_schema,table_name,column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema IN ('payment','integration') ORDER BY table_schema,table_name,ordinal_position");
$constraints = $db->select("SELECT n.nspname AS schema,c.conname,pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname IN ('payment','integration') ORDER BY n.nspname,c.conname");
$indexes = $db->select("SELECT schemaname,indexname,indexdef FROM pg_indexes WHERE schemaname IN ('payment','integration') ORDER BY schemaname,indexname");
$columnNames = array_map(static fn ($c) => $c->table_schema.'.'.$c->table_name.'.'.$c->column_name, $columns);
$constraintNames = array_column($constraints, 'conname');
$indexNames = array_column($indexes, 'indexname');
$missing = []; $sources = []; $checkedColumns = []; $checkedConstraints = []; $checkedIndexes = [];
foreach ($files as $file) {
    $name = pathinfo($file, PATHINFO_FILENAME);
    $source = file_get_contents($file);
    $sources[$name] = hash('sha256', $source);
    $up = explode('public function down', $source)[0];
    preg_match_all("/Schema::connection\('(payment|platform)'\)->(?:create|table)\('([^']+)',\s*(?:static\s+)?function\s*\([^)]*\)[^{]*\{(.*?)\}\);/s", $up, $tables, PREG_SET_ORDER);
    foreach ($tables as $table) {
        $qualified = str_contains($table[2], '.') ? $table[2] : 'payment.'.$table[2];
        preg_match_all('/\$table->(?:uuid|string|boolean|smallInteger|integer|bigInteger|unsignedInteger|unsignedBigInteger|timestampTz|timestamp|jsonb|json|text|char)\(\s*\x27([^\x27]+)\x27/', $table[3], $fields);
        foreach ($fields[1] as $field) {
            $expected = $qualified.'.'.$field;
            $checkedColumns[] = $expected;
            if (! in_array($expected, $columnNames, true)) $missing[] = $expected;
        }
    }
    preg_match_all('/ADD CONSTRAINT\s+([a-z][a-z0-9_]+)/i', $up, $checks);
    foreach ($checks[1] as $check) {
        $checkedConstraints[] = $check;
        if (! in_array($check, $constraintNames, true)) $missing[] = 'constraint:'.$check;
    }
    preg_match_all('/CREATE (?:UNIQUE )?INDEX (?:IF NOT EXISTS )?([a-z][a-z0-9_]+)/i', $up, $keys);
    foreach ($keys[1] as $key) {
        $checkedIndexes[] = $key;
        if (! in_array($key, $indexNames, true)) $missing[] = 'index:'.$key;
    }
}
// Raw SQL/dynamic migrations supplement Blueprint extraction above.
foreach (['payment.payment_idempotency_records' => ['id','actor_id','scope','idempotency_key','request_hash','response_status','response_body','created_at','expires_at'],
    'payment.payment_attempts' => ['operation_id','quote_id','quote_version','provider_account_version'],
    'payment.payment_intents' => ['active_quote_id'], 'payment.payment_provider_accounts' => ['config_version'],
    'payment.payment_config_change_drafts' => ['account_config_version_at_draft_time'],
    'integration.webhook_events' => ['verified_notification','payload_hash','encrypted_evidence']] as $table => $fields) {
    foreach ($fields as $field) {
        $expected = $table.'.'.$field; $checkedColumns[] = $expected;
        if (! in_array($expected, $columnNames, true)) $missing[] = $expected;
    }
}
$financialCounts = [];
foreach (['payment_intents','payment_attempts','payment_refunds','payment_allocations','payment_quotes','payment_idempotency_records'] as $table) {
    $financialCounts[$table] = $db->table('payment.'.$table)->count();
}
$schemaHash = hash('sha256', json_encode([$columns, $constraints, $indexes], JSON_THROW_ON_ERROR));
$report = ['operation' => 'observed-local-schema-baseline-not-historic-execution', 'source_revision' => 'a72ca74252e4ecb42e08b7a9db398491d04f4778+uncommitted-P10',
    'php' => PHP_VERSION, 'environment' => app()->environment(), 'live_processing' => false,
    'source_sha256' => $sources, 'schema_sha256' => $schemaHash,
    'checked_columns' => array_values(array_unique($checkedColumns)), 'checked_constraints' => array_values(array_unique($checkedConstraints)),
    'checked_indexes' => array_values(array_unique($checkedIndexes)), 'missing_objects' => array_values(array_unique($missing)),
    'financial_record_counts' => $financialCounts, 'adopted_migrations' => []];
if ($missing !== []) {
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
if (in_array('--adopt-local', $argv, true)) {
    if (array_sum($financialCounts) !== 0) throw new RuntimeException('Baseline adoption requires empty financial tables.');
    $platform = \Illuminate\Support\Facades\DB::connection('platform');
    $platform->transaction(function () use ($platform, $sources, &$report): void {
        $platform->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['p10-local-schema-baseline']);
        foreach (array_keys($sources) as $migration) {
            if (! $platform->table('platform.migrations')->where('migration', $migration)->exists()) {
                // Batch 0 explicitly denotes adoption, not an invented historic run.
                $platform->table('platform.migrations')->insert(['migration' => $migration, 'batch' => 0]);
                $report['adopted_migrations'][] = $migration;
            }
        }
    });
}
echo json_encode($report, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
