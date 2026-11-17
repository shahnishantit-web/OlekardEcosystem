<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Local-only logins and scoped fixtures for organisation and event workflows. */
final class DevEventWorkflowUsersSeeder extends Seeder
{
    private const PASSWORDS = [
        'creator' => 'LocalEventCreator2026!',
        'reviewer' => 'LocalEventReviewer2026!',
        'approver' => 'LocalEventApprover2026!',
    ];

    private const ACCOUNTS = [
        'creator' => ['email' => 'event.creator@olekard.local', 'role' => 'local-event-creator'],
        'reviewer' => ['email' => 'event.reviewer@olekard.local', 'role' => 'local-event-reviewer'],
        'approver' => ['email' => 'event.approver@olekard.local', 'role' => 'local-event-approver'],
    ];

    private function stableId(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/event-workflow/'.$name)->toString();
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DevEventWorkflowUsersSeeder only runs in local/testing.');
        }

        $identity = DB::connection('identity');
        $events = DB::connection('community_services');
        $rolePermissions = [
            'creator' => [
                'organisation.registry.read', 'organisation.registry.request', EventPermissions::CREATE, EventPermissions::READ,
                EventPermissions::UPDATE, EventPermissions::SUBMIT, EventPermissions::ASSIGNMENT_GRANT,
                EventPermissions::ASSIGNMENT_REVOKE, EventPermissions::CANCEL_REQUEST,
                EventPermissions::GATE_ASSIGNMENT_MANAGE, EventPermissions::ATTENDANCE_READ,
            ],
            'reviewer' => [
                'organisation.registry.read', 'organisation.registry.review', EventPermissions::READ, EventPermissions::REVIEW,
                EventPermissions::CANCEL_APPROVE, EventPermissions::ATTENDANCE_READ,
            ],
            'approver' => [
                'organisation.registry.read', 'organisation.registry.approve', EventPermissions::READ, EventPermissions::PRICE_APPROVE, EventPermissions::PUBLISH,
                EventPermissions::SALES_RESUME, EventPermissions::COMPLIMENTARY_APPROVE,
                EventPermissions::CURRENCY_ASSIGN,
            ],
        ];

        $roleIds = [];
        foreach (self::ACCOUNTS as $key => $account) {
            $roleIds[$key] = $this->ensureRole($identity, $account['role'], $key);
        }

        $userIds = [];
        foreach (self::ACCOUNTS as $key => $account) {
            $userIds[$key] = $this->ensureUser($identity, $account['email'], $roleIds[$key], self::PASSWORDS[$key]);
        }

        foreach ($rolePermissions as $key => $slugs) {
            foreach ($slugs as $slug) {
                $permissionId = $this->ensurePermission($identity, $slug);
                $identity->table('identity.role_permissions')->insertOrIgnore([
                    'id' => $this->stableId('role-permission/'.$key.'/'.$slug),
                    'role_id' => $roleIds[$key], 'permission_id' => $permissionId,
                    'created_by_actor_id' => $userIds['creator'], 'created_at' => now(),
                ]);
            }
        }

        foreach ($userIds as $key => $userId) {
            $identity->table('identity.user_roles')->insertOrIgnore([
                'id' => $this->stableId('user-role/'.$key), 'user_id' => $userId,
                'role_id' => $roleIds[$key], 'created_by_actor_id' => $userIds['creator'],
                'created_at' => now(),
            ]);
            app(PermissionResolverService::class)->invalidateCache($userId);
        }

        $organisationId = $this->ensureDemoOrganisation($identity, $userIds);
        $this->ensureMemberships($identity, $organisationId, $userIds);
        $this->ensureOrganisationCreatorAssignment($events, $organisationId, $userIds['creator']);
        $eventId = $this->ensureDemoEvent($events, $organisationId, $userIds['creator']);
        $this->ensureEventAssignments($events, $organisationId, $eventId, $userIds);

        $this->command?->info('Local event workflow users and scoped demo fixtures provisioned.');
        $this->command?->table(
            ['Role', 'Email', 'Password', 'User ID'],
            collect(self::ACCOUNTS)->map(fn (array $account, string $key): array => [
                Str::headline($key), $account['email'], self::PASSWORDS[$key], $userIds[$key],
            ])->values()->all(),
        );
        $this->command?->line('Demo organisation ID: '.$organisationId);
        $this->command?->line('Demo event ID: '.$eventId.' (draft; use /community/events to load it)');
        $this->command?->warn('Local/testing only credentials. Publication and sales-resume HTTP routes are not registered in the current API.');
    }

    private function ensureRole($identity, string $slug, string $key): string
    {
        $roleId = $identity->table('identity.roles')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($roleId !== null) {
            return (string) $roleId;
        }
        $roleId = $this->stableId('role/'.$key);
        $identity->table('identity.roles')->insert([
            'id' => $roleId, 'slug' => $slug, 'display_name' => Str::headline($key).' (Local Events)',
            'description' => 'Local event workflow fixture role; least-privilege permissions for manual validation.',
            'is_system_role' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $roleId;
    }

    private function ensurePermission($identity, string $slug): string
    {
        $permission = $identity->table('identity.permissions')->where('slug', $slug)->first();
        if ($permission === null) {
            $permissionId = $this->stableId('permission/'.$slug);
            $identity->table('identity.permissions')->insert([
                'id' => $permissionId, 'slug' => $slug,
                'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                'description' => 'Local workflow permission fixture.',
                'module' => Str::before($slug, '.'), 'is_system_permission' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $permissionId;
        }
        if ($permission->deleted_at !== null) {
            throw new \RuntimeException('Required permission is soft-deleted: '.$slug);
        }

        return (string) $permission->id;
    }

    private function ensureUser($identity, string $email, string $roleId, string $password): string
    {
        $userId = $identity->table('identity.users')->where('email', $email)->value('id');
        $userId = $userId === null ? $this->stableId('user/'.$email) : (string) $userId;
        $identity->table('identity.users')->updateOrInsert(['id' => $userId], [
            'mobile_number' => null, 'email' => $email, 'status' => 'active',
            'actor_type' => 'ADMIN', 'default_role_id' => $roleId,
            'lock_reason' => null, 'locked_at' => null, 'failed_attempt_count' => 0,
            'lockout_episode_count' => 0, 'updated_at' => now(),
        ] + ['created_at' => now()]);

        $identity->table('identity.credentials')->updateOrInsert(
            ['user_id' => $userId, 'credential_type' => 'password'],
            ['id' => $identity->table('identity.credentials')->where('user_id', $userId)->where('credential_type', 'password')->value('id')
                ?? $this->stableId('credential/'.$email), 'credential_hash' => Hash::make($password),
                'status' => 'active', 'failed_attempt_count' => 0, 'expires_at' => null,
                'updated_at' => now(), 'created_at' => now()],
        );

        $fingerprint = hash('sha256', 'admin-web');
        $deviceId = $identity->table('identity.devices')->where('user_id', $userId)
            ->where('device_fingerprint', $fingerprint)->value('id');
        $identity->table('identity.devices')->updateOrInsert(
            ['user_id' => $userId, 'device_fingerprint' => $fingerprint],
            ['id' => $deviceId ?? $this->stableId('device/'.$email), 'device_name' => 'Admin Web (local event fixture)',
                'platform' => 'web', 'status' => 'trusted', 'trusted_at' => now(), 'last_seen_at' => now(),
                'updated_at' => now(), 'created_at' => now()],
        );

        return $userId;
    }

    /** @param array{creator:string,reviewer:string,approver:string} $users */
    private function ensureDemoOrganisation($identity, array $users): string
    {
        $reference = 'local-event-workflow-organisation';
        $existing = $identity->table('identity.organisations')->where('registry_reference', $reference)->first();
        $organisationId = $existing?->id ?? $this->stableId('organisation');
        $identity->table('identity.organisations')->updateOrInsert(['id' => $organisationId], [
            'registry_reference' => $reference, 'display_name' => 'LOCAL DEMO Event Workflow Organisation',
            'status' => 'active', 'evidence_reference' => 'local-demo://event-workflow-organisation',
            'requested_by' => $users['creator'], 'reviewed_by' => $users['reviewer'],
            'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->authorityEvent($identity, 'organisation', $organisationId, 'requested', $users['creator'], null, 'pending_review');
        $this->authorityEvent($identity, 'organisation', $organisationId, 'reviewed', $users['reviewer'], 'pending_review', 'active');

        return (string) $organisationId;
    }

    /** @param array{creator:string,reviewer:string,approver:string} $users */
    private function ensureMemberships($identity, string $organisationId, array $users): void
    {
        foreach ($users as $key => $userId) {
            $membershipId = $identity->table('identity.organisation_memberships')
                ->where('organisation_id', $organisationId)->where('user_id', $userId)->value('id');
            $membershipId = $membershipId ?? $this->stableId('membership/'.$key);
            $requestedBy = $key === 'creator' ? $users['creator'] : $users['creator'];
            $reviewedBy = $key === 'creator' ? $users['reviewer'] : ($key === 'reviewer' ? $users['approver'] : $users['reviewer']);
            $identity->table('identity.organisation_memberships')->updateOrInsert(['id' => $membershipId], [
                'organisation_id' => $organisationId, 'user_id' => $userId, 'status' => 'active',
                'evidence_reference' => 'local-demo://event-workflow-membership/'.$key,
                'requested_by' => $requestedBy, 'reviewed_by' => $reviewedBy,
                'updated_at' => now(), 'created_at' => now(),
            ]);
            $this->authorityEvent($identity, 'membership', $membershipId, 'requested', $requestedBy, null, 'pending_review');
            $this->authorityEvent($identity, 'membership', $membershipId, 'reviewed', $reviewedBy, 'pending_review', 'active');
        }
    }

    private function authorityEvent($identity, string $type, string $subjectId, string $action, string $actorId, ?string $previous, string $next): void
    {
        $eventId = $this->stableId('authority-event/'.$type.'/'.$subjectId.'/'.$action);
        $identity->table('identity.organisation_authority_events')->insertOrIgnore([
            'id' => $eventId, 'subject_type' => $type, 'subject_id' => $subjectId,
            'action' => $action, 'actor_id' => $actorId, 'previous_status' => $previous,
            'new_status' => $next, 'created_at' => now(),
        ]);
    }

    private function ensureOrganisationCreatorAssignment($events, string $organisationId, string $actorId): void
    {
        $this->ensureEventAssignment($events, 'organisation', $organisationId, $organisationId, null, null, $actorId,
            EventPermissions::CREATE, $actorId);
    }

    private function ensureDemoEvent($events, string $organisationId, string $creatorId): string
    {
        $eventId = $this->stableId('draft-event');
        if ($events->table('community_services.event_drafts')->where('id', $eventId)->exists()) {
            return $eventId;
        }
        $zone = new \DateTimeZone('Africa/Gaborone');
        $startsAt = now($zone)->addDays(7)->startOfHour();
        $endsAt = $startsAt->copy()->addHours(3);
        $salesStart = now($zone)->subHour();
        $salesEnd = $startsAt->copy()->subHour();
        $events->table('community_services.event_drafts')->insert([
            'id' => $eventId, 'organisation_id' => $organisationId, 'created_by' => $creatorId,
            'title' => 'LOCAL DEMO Event Workflow Draft', 'organiser_name' => 'OleKard Local Demo Organiser',
            'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 50, 'currency_code' => null,
            'timezone' => 'Africa/Gaborone', 'starts_at' => $startsAt, 'ends_at' => $endsAt,
            'sales_start_at' => $salesStart, 'sales_end_at' => $salesEnd,
            'cancellation_policy' => 'Local demonstration policy.', 'transfer_policy' => 'Ticket transfer is not available in this demo.',
            'entry_policy' => 'Present the ticket QR code at the event gate.', 'max_tickets_per_order' => 4,
            'event_format' => 'physical', 'venue_country_code' => 'BW',
            'workflow_state' => 'draft', 'sales_state' => 'not_published', 'lifecycle_state' => 'active',
            'event_reviewer_id' => null, 'reviewed_version' => null, 'price_approver_id' => null,
            'price_approved_version' => null, 'published_version' => null, 'published_snapshot' => null,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $categoryId = $this->stableId('draft-event-free-category');
        $events->table('community_services.event_draft_categories')->insert([
            'id' => $categoryId, 'event_id' => $eventId, 'name' => 'Local demo free ticket',
            'capacity' => 50, 'price_minor' => 0, 'allocation_type' => 'free',
            'complimentary_quota' => null, 'max_per_person' => 2, 'issued_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $events->table('community_services.event_draft_version_contributors')->insertOrIgnore([
            'id' => $this->stableId('draft-event-v1-contributor'), 'event_id' => $eventId,
            'version' => 1, 'actor_id' => $creatorId, 'created_at' => now(),
        ]);
        $events->table('community_services.event_draft_audit')->insertOrIgnore([
            'id' => $this->stableId('draft-event-created-audit'), 'event_id' => $eventId,
            'actor_id' => $creatorId, 'action' => 'created', 'version' => 1, 'created_at' => now(),
        ]);

        return $eventId;
    }

    /** @param array{creator:string,reviewer:string,approver:string} $users */
    private function ensureEventAssignments($events, string $organisationId, string $eventId, array $users): void
    {
        $assignments = [
            ['creator', EventPermissions::READ], ['creator', EventPermissions::UPDATE], ['creator', EventPermissions::SUBMIT],
            ['creator', EventPermissions::CANCEL_REQUEST], ['creator', EventPermissions::GATE_ASSIGNMENT_MANAGE],
            ['creator', EventPermissions::ATTENDANCE_READ],
            ['reviewer', EventPermissions::READ], ['reviewer', EventPermissions::REVIEW],
            ['reviewer', EventPermissions::CANCEL_APPROVE], ['reviewer', EventPermissions::ATTENDANCE_READ],
            ['approver', EventPermissions::READ], ['approver', EventPermissions::PRICE_APPROVE],
            ['approver', EventPermissions::PUBLISH], ['approver', EventPermissions::SALES_RESUME],
            ['approver', EventPermissions::CURRENCY_ASSIGN], ['approver', EventPermissions::ATTENDANCE_READ],
        ];
        foreach ($assignments as [$key, $permission]) {
            $this->ensureEventAssignment($events, 'event', $eventId, $organisationId, $eventId, null,
                $users[$key], $permission, $users['creator']);
        }
    }

    private function ensureEventAssignment($events, string $scopeType, string $scopeId, string $organisationId,
        ?string $eventId, ?string $gateId, string $actorId, string $permission, string $grantorId): void
    {
        $existing = $events->table('community_services.event_authority_assignments')
            ->where('scope_type', $scopeType)->where('scope_id', $scopeId)
            ->where('actor_id', $actorId)->where('permission_slug', $permission)->whereNull('revoked_at')->first();
        if ($existing !== null) {
            return;
        }
        $assignmentId = $this->stableId('assignment/'.$scopeType.'/'.$scopeId.'/'.$actorId.'/'.$permission);
        $events->table('community_services.event_authority_assignments')->insert([
            'id' => $assignmentId, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'organisation_id' => $organisationId, 'event_id' => $eventId, 'gate_id' => $gateId,
            'actor_id' => $actorId, 'permission_slug' => $permission, 'granted_by_actor_id' => $grantorId,
            'granted_at' => now(), 'revoked_by_actor_id' => null, 'revoked_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $events->table('community_services.event_authority_assignment_audit')->insertOrIgnore([
            'id' => $this->stableId('assignment-audit/'.$scopeType.'/'.$scopeId.'/'.$actorId.'/'.$permission),
            'assignment_id' => $assignmentId, 'action' => 'granted', 'actor_id' => $grantorId,
            'target_actor_id' => $actorId, 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'permission_slug' => $permission, 'reason_code' => 'local_demo_seed',
            'correlation_id' => $this->stableId('correlation/'.$scopeType.'/'.$scopeId.'/'.$actorId.'/'.$permission),
            'created_at' => now(),
        ]);
    }
}
