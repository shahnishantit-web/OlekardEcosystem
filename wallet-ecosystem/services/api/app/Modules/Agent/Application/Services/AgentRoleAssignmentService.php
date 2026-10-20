<?php

declare(strict_types=1);

namespace App\Modules\Agent\Application\Services;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AgentRoleAssignmentService
{
    public function assignAgentRole(string $userId, string $actorId): void
    {
        $role = DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'agent')
            ->first();

        if ($role === null) {
            return;
        }

        DB::connection('identity')->table('identity.user_roles')->insertOrIgnore([
            'id'                  => (string) Uuid::uuid7(),
            'user_id'             => $userId,
            'role_id'             => $role->id,
            'created_by_actor_id' => $actorId,
            'created_at'          => now(),
        ]);
    }
}
