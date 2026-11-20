<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = Illuminate\Support\Facades\DB::connection('identity');
$user = $identity->table('identity.users')->where('email', 'event.creator@olekard.local')->first(['id', 'email', 'default_role_id']);
$memberships = $identity->table('identity.organisation_memberships as m')
    ->join('identity.organisations as o', 'o.id', '=', 'm.organisation_id')
    ->where('m.user_id', $user?->id)
    ->orderBy('o.display_name')
    ->get(['o.display_name', 'o.status as organisation_status', 'm.status as membership_status']);
$config = Illuminate\Support\Facades\DB::connection('platform')->table('platform.configuration_values')
    ->where('config_key', 'community_funeral.http_enabled')->where('status', 'active')
    ->orderByDesc('version')->first(['config_value', 'value_type']);
echo json_encode([
    'creator_found' => $user !== null,
    'memberships' => $memberships,
    'funeral_config' => $config,
], JSON_PRETTY_PRINT);
