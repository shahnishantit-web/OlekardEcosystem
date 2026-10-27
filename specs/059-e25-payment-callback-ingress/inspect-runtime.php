<?php

declare(strict_types=1);

// Read-only readiness inventory. Never prints credentials, payloads or balances.
$api = dirname(__DIR__, 2).'/wallet-ecosystem/services/api';
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection('payment');
$states = [];
foreach (['payment_intents', 'payment_attempts', 'payment_refunds'] as $table) {
    $states[$table] = $db->select('SELECT status, COUNT(*) AS records FROM payment.'.$table.' GROUP BY status');
}
echo json_encode(['php' => PHP_VERSION, 'environment' => app()->environment(),
    'database_host' => config('database.connections.payment.host'), 'states' => $states,
    'live_callback_processing_enabled' => config('payment.live_callback_processing_enabled', false),
    'historic_payment_migrations' => $db->select("SELECT migration,batch FROM reconciliation.migrations WHERE migration LIKE '%payment%' ORDER BY migration"),
    'migration_tables' => $db->select("SELECT table_schema,table_name FROM information_schema.tables WHERE table_name='migrations'"),
    'migration_history' => \Illuminate\Support\Facades\DB::connection()->table('migrations')->where('migration', 'like', '%payment%')->orderBy('migration')->get()->all(),
    'columns' => $db->select("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='payment' AND column_name IN ('operation_id','accounting_status','config_version','active_quote_id','ledger_repair_at')"),
    'attempt_constraints' => $db->select("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='payment.payment_attempts'::regclass AND contype='c'")], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
