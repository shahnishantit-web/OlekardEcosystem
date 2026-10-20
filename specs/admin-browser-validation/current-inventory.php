<?php
declare(strict_types=1);
require __DIR__.'/../../wallet-ecosystem/services/api/vendor/autoload.php';
$app = require __DIR__.'/../../wallet-ecosystem/services/api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$routes = [];
$required = [];
foreach (app('router')->getRoutes() as $route) {
    if (!str_starts_with($route->uri(), 'v1/')) continue;
    $middleware = $route->gatherMiddleware();
    foreach ($middleware as $entry) {
        if (str_starts_with($entry, 'identity.require-permission:')) $required[] = substr($entry, strlen('identity.require-permission:'));
    }
    $routes[] = ['uri'=>'/'.$route->uri(), 'methods'=>$route->methods(), 'middleware'=>$middleware];
}
$db = Illuminate\Support\Facades\DB::connection('identity');
$user = $db->table('identity.users')->where('email','admin@olekard.local')->first();
$declared = $db->table('identity.permissions')->whereNull('deleted_at')->pluck('slug')->all();
$grants = app(App\Modules\Identity\Domain\Services\PermissionResolverService::class)->resolveAllForActor($user->id);
$tables = $db->select("SELECT table_schema, table_name FROM information_schema.tables WHERE table_type='BASE TABLE' AND table_schema NOT IN ('pg_catalog','information_schema','public') ORDER BY table_schema, table_name");
$counts=[];
foreach($tables as $table) $counts[$table->table_schema.'.'.$table->table_name]=$db->table($table->table_schema.'.'.$table->table_name)->count();
$result=['routes'=>$routes,'permission_count'=>count($declared),'grant_count'=>count($grants),'missing_definitions'=>array_values(array_diff(array_unique($required),$declared)),'missing_grants'=>array_values(array_diff(array_unique($required),$grants)),'counts'=>$counts];
$result['samples']=['admin'=>$user->id];
foreach(['wallet'=>'wallet.wallets','agent'=>'agent.agents','role'=>'identity.roles','kyc'=>'kyc.kyc_cases','transaction'=>'transaction.transaction_projections','finding'=>'security.vulnerability_findings','alert'=>'risk.risk_alerts','case'=>'support.support_tickets','batch'=>'reconciliation.reconciliation_batches','settlement'=>'reconciliation.settlement_batches','device'=>'identity.devices','report'=>'reporting.report_jobs','qa'=>'qa.test_runs'] as $key=>$table) {
    $result['samples'][$key]=$db->table($table)->value('id');
}
$result['samples']['customer']=$db->table('identity.users')->where('actor_type','CUSTOMER')->value('id');
file_put_contents(__DIR__.'/current-inventory.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['route_count'=>count($routes),'permission_count'=>count($declared),'grant_count'=>count($grants),'missing_definitions'=>$result['missing_definitions'],'missing_grants'=>$result['missing_grants']]);
