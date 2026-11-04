<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    private const array PERMISSIONS = [
        ['slug' => 'platform.financial_terms.draft', 'display_name' => 'Draft OleKard Financial Terms'],
        ['slug' => 'platform.financial_terms.approve', 'display_name' => 'Approve OleKard Financial Terms'],
        ['slug' => 'platform.financial_terms.publish', 'display_name' => 'Publish OleKard Financial Terms'],
    ];

    public function up(): void
    {
        $now = now()->toDateTimeString();
        foreach (self::PERMISSIONS as $permission) {
            DB::connection('identity')->table('identity.permissions')->insertOrIgnore([
                'id' => (string) Str::orderedUuid(), 'slug' => $permission['slug'],
                'display_name' => $permission['display_name'], 'description' => null,
                'module' => 'platform', 'is_system_permission' => true, 'deleted_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // Deliberately do not grant these permissions to any existing role.
    }

    public function down(): void
    {
        $ids = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', array_column(self::PERMISSIONS, 'slug'))->pluck('id');
        DB::connection('identity')->table('identity.role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::connection('identity')->table('identity.permissions')->whereIn('id', $ids)->delete();
    }
};
