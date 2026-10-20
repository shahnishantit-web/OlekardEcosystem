<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        $permissions = [
            ['slug' => 'risk.evaluate',         'display_name' => 'Evaluate Transaction Risk (System/Officer)'],
            ['slug' => 'risk.configure_rules',  'display_name' => 'Configure Risk Rules (Admin)'],
            ['slug' => 'risk.view_alerts',      'display_name' => 'View Risk Alerts (Officer)'],
            ['slug' => 'risk.manage_alerts',    'display_name' => 'Investigate and Resolve Risk Alerts (Officer)'],
            ['slug' => 'risk.freeze_account',   'display_name' => 'Freeze/Lift Account Due to Risk (Officer)'],
            ['slug' => 'risk.file_sar',         'display_name' => 'File and Submit Suspicious Activity Reports (Compliance Officer)'],
        ];

        $now = now()->toDateTimeString();

        foreach ($permissions as $perm) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id'                   => (string) Str::orderedUuid(),
                'slug'                 => $perm['slug'],
                'display_name'         => $perm['display_name'],
                'description'          => null,
                'module'               => 'risk',
                'is_system_permission' => true,
                'deleted_at'           => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }

        $allPermIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column($permissions, 'slug'))
            ->pluck('id', 'slug');

        $adminRoleId = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')
            ->value('id');

        if ($adminRoleId !== null) {
            foreach ($allPermIds as $permId) {
                DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                    'id'                  => (string) Str::orderedUuid(),
                    'role_id'             => $adminRoleId,
                    'permission_id'       => $permId,
                    'created_by_actor_id' => null,
                    'created_at'          => $now,
                ]);
            }
        }

        // Seed score-threshold config keys with safe defaults (constitution P2/P3 — never hard-code in domain logic).
        $configConn = DB::connection('platform');
        $configKeys = [
            ['key' => 'risk.block_score_threshold',  'value' => '85',  'type' => 'integer'],
            ['key' => 'risk.review_score_threshold', 'value' => '50',  'type' => 'integer'],
        ];
        foreach ($configKeys as $cfg) {
            $configConn->table('platform.configuration_values')->upsert(
                [
                    'id'           => (string) Str::orderedUuid(),
                    'config_key'   => $cfg['key'],
                    'config_value' => $cfg['value'],
                    'value_type'   => $cfg['type'],
                    'status'       => 'active',
                    'version'      => 1,
                    'is_tbd'       => false,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                ['config_key', 'version'],
                ['config_value', 'value_type', 'is_tbd', 'updated_at'],
            );
        }
    }

    public function down(): void
    {
        $slugs = ['risk.evaluate', 'risk.configure_rules', 'risk.view_alerts', 'risk.manage_alerts', 'risk.freeze_account', 'risk.file_sar'];

        $permissionIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id');

        DB::connection('identity')->table('identity.role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->delete();

        DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', $slugs)
            ->where('is_system_permission', true)
            ->delete();

        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', ['risk.block_score_threshold', 'risk.review_score_threshold'])
            ->delete();
    }
};
