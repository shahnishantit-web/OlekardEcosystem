<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    protected $connection = 'identity';

    public function up(): void
    {
        $actorTypeMap = [
            'CUSTOMER' => 'customer',
            'AGENT'    => 'agent',
            'ADMIN'    => 'admin',
        ];

        foreach ($actorTypeMap as $actorType => $roleSlug) {
            $role = DB::connection('identity')->table('identity.roles')
                ->where('slug', $roleSlug)
                ->first(['id']);

            if ($role === null) {
                Log::warning('backfill_user_default_roles: role not found', ['slug' => $roleSlug]);
                continue;
            }

            DB::connection('identity')->table('identity.users')
                ->where('actor_type', $actorType)
                ->whereNull('default_role_id')
                ->orderBy('created_at')
                ->chunkById(500, function ($users) use ($actorType, $role): void {
                    $ids = $users->pluck('id')->all();
                    DB::connection('identity')->table('identity.users')
                        ->whereIn('id', $ids)
                        ->whereNull('default_role_id')
                        ->update(['default_role_id' => $role->id]);
                });
        }

        // Log any remaining users with unrecognized actor_type
        $unrecognized = DB::connection('identity')->table('identity.users')
            ->whereNull('default_role_id')
            ->whereNotIn('actor_type', array_keys($actorTypeMap))
            ->count();

        if ($unrecognized > 0) {
            Log::warning('backfill_user_default_roles: unrecognized actor_type rows skipped', [
                'count' => $unrecognized,
            ]);
        }
    }

    public function down(): void
    {
        // Rollback is not safe — we cannot distinguish backfilled from previously-set values.
        // Intentionally a no-op.
    }
};
