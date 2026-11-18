<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Local-only gate validation assignments for the customer event demo fixtures. */
final class DevEventGateAttendantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo gate assignments are local/testing only.');
        }
        $identity = DB::connection('identity');
        $events = DB::connection('community_services');
        $platform = DB::connection('platform');
        $agentId = $identity->table('identity.users')->where('mobile_number', '+26771000002')->value('id');
        $agentIsActive = $platform->table('agent.agents')->where('user_id', $agentId)->where('status', 'active')->exists();
        $creatorId = $identity->table('identity.users')->where('email', 'event.creator@olekard.local')->value('id');
        if (! $agentId || ! $agentIsActive || ! $creatorId) {
            throw new \RuntimeException('Run CustomerAgentSeeder, DevAgentSeeder, and DevFiveOrganisationEventsSeeder first.');
        }

        $permissionId = $identity->table('identity.permissions')
            ->where('slug', EventPermissions::GATE_VALIDATE)->whereNull('deleted_at')->value('id');
        if (! $permissionId) {
            throw new \RuntimeException('Run the Events migrations before assigning gate validation permission.');
        }
        $role = $identity->table('identity.roles')->where('slug', 'local-event-gate-attendant')->first(['id']);
        $roleId = $role->id ?? $this->id('role/local-event-gate-attendant');
        if ($role === null) {
            $identity->table('identity.roles')->insert([
                'id' => $roleId, 'slug' => 'local-event-gate-attendant',
                'display_name' => 'Local Event Gate Attendant',
                'description' => 'Local-only role allowing gate validation for specifically assigned demo events.',
                'is_system_role' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $identity->table('identity.role_permissions')->insertOrIgnore([
            'id' => $this->id('role-permission/local-event-gate-attendant/'.EventPermissions::GATE_VALIDATE),
            'role_id' => $roleId, 'permission_id' => $permissionId,
            'created_by_actor_id' => $creatorId, 'created_at' => now(),
        ]);
        $identity->table('identity.user_roles')->insertOrIgnore([
            'id' => $this->id('user-role/local-event-gate-attendant/'.$agentId),
            'user_id' => $agentId, 'role_id' => $roleId,
            'created_by_actor_id' => $creatorId, 'created_at' => now(),
        ]);
        app(\App\Modules\Identity\Domain\Services\PermissionResolverService::class)->invalidateCache($agentId);

        $rows = [];
        for ($org = 0; $org < 5; $org++) {
            $organisationId = $this->id('org/'.$org);
            for ($number = 1; $number <= 2; $number++) {
                $eventId = $this->id("event/{$org}/{$number}");
                $event = $events->table('community_services.event_drafts')->where('id', $eventId)->first();
                if (! $event) {
                    throw new \RuntimeException('Run DevFiveOrganisationEventsSeeder before assigning gate attendants.');
                }
                $gateId = $this->id("gate/{$org}/{$number}");
                $now = now();
                $events->table('community_services.event_gates')->updateOrInsert(['id' => $gateId], [
                    'organisation_id' => $organisationId, 'event_id' => $eventId,
                    'name' => 'Main Entrance', 'status' => 'active', 'created_by_actor_id' => $creatorId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $assignmentId = $this->id('assignment/'.$gateId.'/'.$agentId.'/'.EventPermissions::GATE_VALIDATE);
                $existing = $events->table('community_services.event_authority_assignments')
                    ->where('scope_type', 'gate')->where('scope_id', $gateId)->where('actor_id', $agentId)
                    ->where('permission_slug', EventPermissions::GATE_VALIDATE)->whereNull('revoked_at')->exists();
                if (! $existing) {
                    $events->table('community_services.event_authority_assignments')->insert([
                        'id' => $assignmentId, 'scope_type' => 'gate', 'scope_id' => $gateId,
                        'organisation_id' => $organisationId, 'event_id' => $eventId, 'gate_id' => $gateId,
                        'actor_id' => $agentId, 'permission_slug' => EventPermissions::GATE_VALIDATE,
                        'granted_by_actor_id' => $creatorId, 'granted_at' => $now,
                        'revoked_by_actor_id' => null, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $assignmentId = (string) $events->table('community_services.event_authority_assignments')
                        ->where('scope_type', 'gate')->where('scope_id', $gateId)->where('actor_id', $agentId)
                        ->where('permission_slug', EventPermissions::GATE_VALIDATE)->whereNull('revoked_at')->value('id');
                }
                $events->table('community_services.event_authority_assignment_audit')->insertOrIgnore([
                    'id' => $this->id('assignment-audit/'.$assignmentId), 'assignment_id' => $assignmentId,
                    'action' => 'granted', 'actor_id' => $creatorId, 'target_actor_id' => $agentId,
                    'scope_type' => 'gate', 'scope_id' => $gateId,
                    'permission_slug' => EventPermissions::GATE_VALIDATE, 'reason_code' => 'local_demo_seed',
                    'correlation_id' => $this->id('correlation/'.$assignmentId), 'created_at' => $now,
                ]);
                $events->table('community_services.event_gate_audit')->insertOrIgnore([
                    'id' => $this->id('gate-audit/'.$gateId), 'gate_id' => $gateId, 'event_id' => $eventId,
                    'actor_id' => $creatorId, 'action' => 'created', 'correlation_id' => $this->id('gate-correlation/'.$gateId),
                    'created_at' => $now,
                ]);
                $rows[] = [$event->title, $eventId, 'Main Entrance', $gateId];
            }
        }

        $this->command?->info('Active local gate validation assignments granted to +26771000002 for all 10 customer demo events.');
        $this->command?->table(['Event', 'Event ID', 'Gate', 'Gate ID'], $rows);
    }

    private function id(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/customer-event-demo/'.$name)->toString();
    }
}
