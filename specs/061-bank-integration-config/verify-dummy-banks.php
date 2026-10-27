<?php

declare(strict_types=1);

$api = dirname(__DIR__, 2).'/wallet-ecosystem/services/api';
require $api.'/vendor/autoload.php';
$app = require $api.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$banks = \App\Modules\Payment\Infrastructure\Models\PaymentProviderModel::whereIn('code', ['bank1', 'bank2', 'bank3'])
    ->withCount('accounts')->orderBy('code')->get(['id', 'code', 'display_name', 'environment', 'is_enabled', 'bank_lifecycle_status']);
$permission = \Illuminate\Support\Facades\DB::connection('identity')->table('identity.role_permissions as rp')
    ->join('identity.roles as r', 'r.id', '=', 'rp.role_id')->join('identity.permissions as p', 'p.id', '=', 'rp.permission_id')
    ->where('r.slug', 'admin')->where('p.slug', 'payment.configuration')->exists();
echo json_encode(['banks' => $banks->toArray(), 'admin_has_configuration_permission' => $permission,
    'live_processing' => config('payment.live_callback_processing_enabled', false)], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
