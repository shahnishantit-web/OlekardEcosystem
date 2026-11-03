<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Events\Domain\Permissions\EventPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Synthetic, least-privilege fixtures. Refuses every database except the dedicated P26 E2E target. */
final class P26BrowserE2ESeeder extends Seeder
{
    private const ORGANISATION = 'f2600000-0000-7000-8000-000000000001';
    private const EVENT = 'f2600000-0000-7000-8000-000000000002';
    private const COMPLIMENTARY_CATEGORY = 'f2600000-0000-7000-8000-000000000003';
    private const FREE_CATEGORY = 'f2600000-0000-7000-8000-000000000004';
    private const GATE = 'f2600000-0000-7000-8000-000000000005';
    private const NATIVE_EVENT = 'f2600000-0000-7000-8000-000000000006';
    private const NATIVE_CATEGORY = 'f2600000-0000-7000-8000-000000000007';
    private const NATIVE_GATE = 'f2600000-0000-7000-8000-000000000008';
    private const NATIVE_WRONG_GATE = 'f2600000-0000-7000-8000-000000000009';
    // Public test-only fixture credential. Never use this value outside isolated tests.
    private const NATIVE_CREDENTIAL = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFG';

    public function run(): void
    {
        $database = (string) config('database.connections.platform.database');
        if (! app()->environment('testing')
            || env('P26_E2E_FIXTURE_APPROVALS') !== '1'
            || preg_match('/\Awallet_ecosystem_p26_e2e_[0-9]{8}_[a-f0-9]{8}\z/', $database) !== 1) {
            throw new \RuntimeException('P26 E2E seeding requires APP_ENV=testing and a dedicated dated P26 E2E database.');
        }

        $password = (string) env('P26_E2E_ADMIN_PASSWORD', '');
        $pin = (string) env('P26_E2E_CUSTOMER_PIN', '');
        $operatorPin = (string) env('P26_E2E_OPERATOR_PIN', '');
        $totpSecret = (string) env('P26_E2E_ADMIN_TOTP_SECRET', '');
        if (strlen($password) < 16 || preg_match('/\A[0-9]{6}\z/', $pin) !== 1
            || preg_match('/\A[0-9]{6}\z/', $operatorPin) !== 1
            || preg_match('/\A[A-Z2-7]{16,64}\z/', $totpSecret) !== 1) {
            throw new \RuntimeException('P26 E2E fixture credentials must be supplied through the process environment.');
        }

        $adminId = $this->user('admin@p26-e2e.invalid', null, 'ADMIN', $password, 'password', 'admin-web');
        DB::connection('identity')->table('identity.credentials')->insert([
            'id' => (string) Uuid::uuid7(), 'user_id' => $adminId, 'credential_type' => 'mfa_totp',
            'credential_hash' => $totpSecret, 'status' => 'active', 'failed_attempt_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customerId = $this->user(null, '+26771009991', 'CUSTOMER', $pin, 'pin', 'mobile-customer-+26771009991');
        $otherCustomerId = $this->user(null, '+26771009992', 'CUSTOMER', $pin, 'pin', 'mobile-customer-+26771009992');
        $capacityCustomerId = $this->user(null, '+26771009994', 'CUSTOMER', $pin, 'pin', 'mobile-customer-+26771009994');
        $overflowCustomerId = $this->user(null, '+26771009995', 'CUSTOMER', $pin, 'pin', 'mobile-customer-+26771009995');
        $operatorId = $this->user(null, '+26771009993', 'AGENT', $operatorPin, 'pin', 'mobile-agent-+26771009993');
        $membershipReviewerId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $membershipReviewerId, 'email' => 'p26-membership-reviewer@invalid',
            'status' => 'active', 'actor_type' => 'ADMIN', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $adminRole = $this->role('p26_e2e_organiser', [
            EventPermissions::CREATE, EventPermissions::READ, EventPermissions::UPDATE,
            EventPermissions::ATTENDANCE_READ,
        ]);
        $operatorRole = $this->role('p26_e2e_gate_operator', [EventPermissions::GATE_VALIDATE]);
        DB::connection('identity')->table('identity.users')->where('id', $adminId)->update(['default_role_id' => $adminRole]);
        DB::connection('identity')->table('identity.users')->where('id', $operatorId)->update(['default_role_id' => $operatorRole]);

        $now = now();
        $db = DB::connection('community_services');
        $db->table('community_services.event_drafts')->insert([
            'id' => self::EVENT, 'organisation_id' => self::ORGANISATION, 'created_by' => $adminId,
            'title' => 'P26 isolated browser event', 'organiser_name' => 'P26 E2E fixture organiser',
            'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 4, 'currency_code' => 'BWP',
            'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2),
            'ends_at' => $now->copy()->addDays(2)->addHours(3), 'sales_start_at' => $now->copy()->subHour(),
            'sales_end_at' => $now->copy()->addDays(2), 'cancellation_policy' => 'Test fixture only',
            'transfer_policy' => 'Transfers unavailable', 'entry_policy' => 'Online validation only',
            'version' => 1, 'workflow_state' => 'published', 'sales_state' => 'active',
            'event_reviewer_id' => $adminId, 'reviewed_version' => 1, 'published_version' => 1,
            'published_snapshot' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'event_format' => 'physical', 'venue_country_code' => 'BW',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_draft_categories')->insert([
            ['id' => self::COMPLIMENTARY_CATEGORY, 'event_id' => self::EVENT, 'name' => 'Complimentary fixture',
                'capacity' => 2, 'price_minor' => 0, 'allocation_type' => 'complimentary',
                'complimentary_quota' => 2, 'max_per_person' => 1, 'issued_quantity' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::FREE_CATEGORY, 'event_id' => self::EVENT, 'name' => 'Free fixture',
                'capacity' => 2, 'price_minor' => 0, 'allocation_type' => 'free',
                'complimentary_quota' => null, 'max_per_person' => 1, 'issued_quantity' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $db->table('community_services.event_gates')->insert([
            'id' => self::GATE, 'organisation_id' => self::ORGANISATION, 'event_id' => self::EVENT,
            'name' => 'P26 E2E gate', 'status' => 'active', 'created_by_actor_id' => $adminId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_drafts')->insert([
            'id' => self::NATIVE_EVENT, 'organisation_id' => self::ORGANISATION, 'created_by' => $adminId,
            'title' => 'P26 isolated native event', 'organiser_name' => 'P26 E2E fixture organiser',
            'sponsors' => json_encode([], JSON_THROW_ON_ERROR), 'capacity' => 2, 'currency_code' => 'BWP',
            'timezone' => 'Africa/Gaborone', 'starts_at' => $now->copy()->addDays(2),
            'ends_at' => $now->copy()->addDays(2)->addHours(3), 'sales_start_at' => $now->copy()->subHour(),
            'sales_end_at' => $now->copy()->addDays(2), 'cancellation_policy' => 'Test fixture only',
            'transfer_policy' => 'Transfers unavailable', 'entry_policy' => 'Online validation only',
            'version' => 1, 'workflow_state' => 'published', 'sales_state' => 'active',
            'event_reviewer_id' => $adminId, 'reviewed_version' => 1, 'published_version' => 1,
            'published_snapshot' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'event_format' => 'physical', 'venue_country_code' => 'BW', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_draft_categories')->insert([
            'id' => self::NATIVE_CATEGORY, 'event_id' => self::NATIVE_EVENT, 'name' => 'Native free fixture',
            'capacity' => 2, 'price_minor' => 0, 'allocation_type' => 'free', 'complimentary_quota' => null,
            'max_per_person' => 2, 'issued_quantity' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_gates')->insert([
            ['id' => self::NATIVE_GATE, 'organisation_id' => self::ORGANISATION, 'event_id' => self::NATIVE_EVENT,
                'name' => 'P26 native assigned gate', 'status' => 'active', 'created_by_actor_id' => $adminId,
                'created_at' => $now, 'updated_at' => $now],
            ['id' => self::NATIVE_WRONG_GATE, 'organisation_id' => self::ORGANISATION, 'event_id' => self::NATIVE_EVENT,
                'name' => 'P26 native unassigned gate', 'status' => 'active', 'created_by_actor_id' => $adminId,
                'created_at' => $now, 'updated_at' => $now],
        ]);
        $nativeRegistrationId = (string) Uuid::uuid7();
        $db->table('community_services.event_free_registrations')->insert([
            'id' => $nativeRegistrationId, 'event_id' => self::NATIVE_EVENT, 'organisation_id' => self::ORGANISATION,
            'category_id' => self::NATIVE_CATEGORY, 'invitation_id' => null, 'attendee_actor_id' => $overflowCustomerId,
            'entitlement_source' => 'free', 'idempotency_key' => 'p26-native-issued-ticket-fixture',
            'request_hash' => hash('sha256', 'p26-native-issued-ticket-fixture'), 'status' => 'registered',
            'registered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('community_services.event_tickets')->insert([
            'id' => (string) Uuid::uuid7(), 'order_id' => null, 'order_line_id' => null,
            'free_registration_id' => $nativeRegistrationId, 'entitlement_source' => 'free', 'entitlement_number' => 1,
            'event_id' => self::NATIVE_EVENT, 'organisation_id' => self::ORGANISATION, 'owner_actor_id' => $overflowCustomerId,
            'category_id' => self::NATIVE_CATEGORY, 'category_name' => 'Native free fixture',
            'credential_hash' => hash('sha256', self::NATIVE_CREDENTIAL),
            'credential_encrypted' => Crypt::encryptString(self::NATIVE_CREDENTIAL), 'status' => 'valid',
            'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Test-only pre-reviewed Identity records satisfy the existing
        // authoring prerequisite; they are not operational approval evidence.
        $identity = DB::connection('identity');
        $identity->table('identity.organisations')->insert([
            'id' => self::ORGANISATION, 'registry_reference' => 'p26-e2e-fixture-org',
            'display_name' => 'P26 E2E fixture organiser', 'status' => 'active',
            'evidence_reference' => 'test-fixture://p26/organisation-review',
            'requested_by' => $adminId, 'reviewed_by' => $membershipReviewerId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $identity->table('identity.organisation_memberships')->insert([
            'id' => (string) Uuid::uuid7(), 'organisation_id' => self::ORGANISATION,
            'user_id' => $adminId, 'status' => 'active',
            'evidence_reference' => 'test-fixture://p26/organiser-membership-review',
            'requested_by' => $adminId, 'reviewed_by' => $membershipReviewerId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $identity->table('identity.organisation_authority_events')->insert([
            [
                'id' => (string) Uuid::uuid7(), 'subject_type' => 'organisation',
                'subject_id' => self::ORGANISATION, 'action' => 'reviewed',
                'actor_id' => $membershipReviewerId, 'previous_status' => 'pending_review',
                'new_status' => 'active', 'created_at' => $now,
            ],
            [
                'id' => (string) Uuid::uuid7(), 'subject_type' => 'membership',
                'subject_id' => DB::connection('identity')->table('identity.organisation_memberships')
                    ->where('organisation_id', self::ORGANISATION)->where('user_id', $adminId)->value('id'),
                'action' => 'reviewed', 'actor_id' => $membershipReviewerId,
                'previous_status' => 'pending_review', 'new_status' => 'active', 'created_at' => $now,
            ],
        ]);

        $this->assignment('organisation', self::ORGANISATION, self::ORGANISATION, null, null, $adminId, EventPermissions::CREATE, $adminId, $now);
        foreach ([EventPermissions::READ, EventPermissions::UPDATE, EventPermissions::ATTENDANCE_READ] as $permission) {
            $this->assignment('event', self::EVENT, self::ORGANISATION, self::EVENT, null, $adminId, $permission, $adminId, $now);
        }
        $this->assignment('gate', self::GATE, self::ORGANISATION, self::EVENT, self::GATE, $operatorId,
            EventPermissions::GATE_VALIDATE, $adminId, $now);
        $this->assignment('gate', self::NATIVE_GATE, self::ORGANISATION, self::NATIVE_EVENT, self::NATIVE_GATE, $operatorId,
            EventPermissions::GATE_VALIDATE, $adminId, $now);

        // Safe identifiers only; no credential, token or approval secret is written to a file or log.
        $this->command?->line(json_encode([
            'event_id' => self::EVENT, 'organisation_id' => self::ORGANISATION,
            'complimentary_category_id' => self::COMPLIMENTARY_CATEGORY,
            'free_category_id' => self::FREE_CATEGORY, 'gate_id' => self::GATE,
            'admin_email' => 'admin@p26-e2e.invalid', 'customer_id' => $customerId,
            'other_customer_id' => $otherCustomerId, 'capacity_customer_id' => $capacityCustomerId,
            'overflow_customer_id' => $overflowCustomerId, 'operator_id' => $operatorId,
            'native_event_id' => self::NATIVE_EVENT, 'native_category_id' => self::NATIVE_CATEGORY,
            'native_gate_id' => self::NATIVE_GATE, 'native_wrong_gate_id' => self::NATIVE_WRONG_GATE,
        ], JSON_THROW_ON_ERROR));
    }

    private function user(?string $email, ?string $phone, string $actorType, string $secret, string $credentialType, string $fingerprint): string
    {
        $id = (string) Uuid::uuid7();
        $now = now();
        $identity = DB::connection('identity');
        $identity->table('identity.users')->insert([
            'id' => $id, 'email' => $email, 'mobile_number' => $phone, 'status' => 'active',
            'actor_type' => $actorType, 'failed_attempt_count' => 0, 'lockout_episode_count' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $identity->table('identity.credentials')->insert([
            'id' => (string) Uuid::uuid7(), 'user_id' => $id, 'credential_type' => $credentialType,
            'credential_hash' => Hash::make($secret), 'status' => 'active', 'failed_attempt_count' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $identity->table('identity.devices')->insert([
            'id' => (string) Uuid::uuid7(), 'user_id' => $id, 'device_fingerprint' => hash('sha256', $fingerprint),
            'device_name' => 'P26 isolated browser fixture', 'platform' => 'web', 'status' => 'trusted',
            'trusted_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /** @param list<string> $permissions */
    private function role(string $slug, array $permissions): string
    {
        $identity = DB::connection('identity');
        $roleId = (string) Uuid::uuid7();
        $identity->table('identity.roles')->insert([
            'id' => $roleId, 'slug' => $slug, 'display_name' => Str::headline($slug),
            'description' => 'Isolated P26 browser E2E fixture only', 'is_system_role' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($permissions as $permission) {
            $permissionId = $identity->table('identity.permissions')->where('slug', $permission)->whereNull('deleted_at')->value('id');
            if ($permissionId === null) {
                throw new \RuntimeException('Required Events permission is not registered: '.$permission);
            }
            $identity->table('identity.role_permissions')->insert([
                'id' => (string) Uuid::uuid7(), 'role_id' => $roleId,
                'permission_id' => $permissionId, 'created_at' => now(),
            ]);
        }

        return $roleId;
    }

    private function assignment(string $scopeType, string $scopeId, string $organisationId, ?string $eventId, ?string $gateId,
        string $actorId, string $permission, string $grantorId, \Illuminate\Support\Carbon $now): void
    {
        DB::connection('community_services')->table('community_services.event_authority_assignments')->insert([
            'id' => (string) Uuid::uuid7(), 'scope_type' => $scopeType, 'scope_id' => $scopeId,
            'organisation_id' => $organisationId, 'event_id' => $eventId, 'gate_id' => $gateId,
            'actor_id' => $actorId, 'permission_slug' => $permission, 'granted_by_actor_id' => $grantorId,
            'granted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
