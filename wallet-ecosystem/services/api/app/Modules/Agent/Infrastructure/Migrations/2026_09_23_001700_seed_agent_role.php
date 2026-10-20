<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'identity';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    // Permissions assigned to the `agent` role (FR-E12-011)
    private const AGENT_ROLE_PERMISSION_SLUGS = [
        'agent.application.create',
        'agent.application.read',
        'agent.customer.search',
        'agent.customer.register',
        'agent.customer.kyc.initiate',
        'agent.customer.wallet.read',
        'agent.customer.transactions.read',
    ];

    public function up(): void
    {
        $now = $this->now();

        DB::connection('identity')->table('identity.roles')->insertOrIgnore([
            'id'             => (string) Str::orderedUuid(),
            'slug'           => 'agent',
            'display_name'   => 'Agent',
            'description'    => 'Role assigned to approved agent users. Grants agent self-service and customer management permissions.',
            'is_system_role' => true,
            'deleted_at'     => null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $agentRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'agent')
            ->first(['id']);

        if ($agentRole === null) {
            return;
        }

        $permIds = DB::connection('identity')->table('identity.permissions')
            ->whereIn('slug', self::AGENT_ROLE_PERMISSION_SLUGS)
            ->pluck('id', 'slug');

        foreach (self::AGENT_ROLE_PERMISSION_SLUGS as $slug) {
            $permId = $permIds[$slug] ?? null;
            if ($permId === null) {
                continue;
            }

            DB::connection('identity')->table('identity.role_permissions')->insertOrIgnore([
                'id'                  => (string) Str::orderedUuid(),
                'role_id'             => $agentRole->id,
                'permission_id'       => $permId,
                'created_by_actor_id' => null,
                'created_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        $agentRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'agent')
            ->first(['id']);

        if ($agentRole !== null) {
            DB::connection('identity')->table('identity.role_permissions')
                ->where('role_id', $agentRole->id)
                ->delete();

            DB::connection('identity')->table('identity.roles')
                ->where('slug', 'agent')
                ->where('is_system_role', true)
                ->delete();
        }
    }
};
