<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Supplements CustomerAgentSeeder with the extra rows the Flutter agent app login
 * flow requires but that the base seeder omits:
 *
 *   - identity.devices            — phone-specific pre-trusted fingerprint
 *                                   sha256("mobile-agent-+26771000002")
 *   - identity.user_roles         — 'agent' role assignment
 *   - profile.user_profiles       — first_name / last_name / mobile_number
 *   - agent.agent_applications    — status=approved, kyc_result=passed
 *   - agent.agents                — status=active
 *
 * CustomerAgentSeeder MUST run first (identity.users + credentials already present).
 *
 * The agent login controller uses rawDeviceFingerprint = "mobile-agent-{phone}",
 * which matches the fingerprint seeded here.
 *
 * NOT for production — guarded to non-production environments only.
 */
final class DevAgentSeeder extends Seeder
{
    private const PHONE                  = '+26771000002';
    private const DEVICE_FINGERPRINT_RAW = 'mobile-agent-+26771000002';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DevAgentSeeder skipped in production.');
            return;
        }

        $now      = now();
        $identity = DB::connection('identity');
        $platform = DB::connection('platform');

        $userId = $identity->table('identity.users')
            ->where('mobile_number', self::PHONE)
            ->value('id');

        if ($userId === null) {
            $this->command?->warn('DevAgentSeeder: user not found — run CustomerAgentSeeder first.');
            return;
        }

        // ── Phone-specific pre-trusted device ─────────────────────────────────

        $fingerprintHash = hash('sha256', self::DEVICE_FINGERPRINT_RAW);
        $deviceRow = [
            'device_name'  => 'Agent App — phone fingerprint (seeded)',
            'platform'     => 'mobile',
            'status'       => 'trusted',
            'trusted_at'   => $now,
            'last_seen_at' => $now,
            'updated_at'   => $now,
        ];

        $deviceId = $identity->table('identity.devices')
            ->where('user_id', $userId)
            ->where('device_fingerprint', $fingerprintHash)
            ->value('id');

        if ($deviceId === null) {
            $identity->table('identity.devices')->insert($deviceRow + [
                'id'                 => (string) Uuid::uuid7(),
                'user_id'            => $userId,
                'device_fingerprint' => $fingerprintHash,
                'created_at'         => $now,
            ]);
        } else {
            $identity->table('identity.devices')->where('id', $deviceId)->update($deviceRow);
        }

        // ── Agent role assignment ─────────────────────────────────────────────

        $roleId = $identity->table('identity.roles')
            ->where('slug', 'agent')
            ->value('id');

        if ($roleId !== null) {
            $hasRole = $identity->table('identity.user_roles')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->exists();

            if (!$hasRole) {
                $identity->table('identity.user_roles')->insert([
                    'id'                   => (string) Uuid::uuid7(),
                    'user_id'              => $userId,
                    'role_id'              => $roleId,
                    'created_by_actor_id'  => null,
                    'created_at'           => $now,
                ]);
            }

            $identity->table('identity.users')
                ->where('id', $userId)
                ->update(['default_role_id' => $roleId, 'updated_at' => $now]);
        }

        // ── Profile ───────────────────────────────────────────────────────────

        DB::connection('profile')->table('profile.user_profiles')->upsert(
            [
                'id'             => (string) Uuid::uuid7(),
                'user_id'        => $userId,
                'first_name'     => 'Test',
                'last_name'      => 'Agent',
                'mobile_number'  => self::PHONE,
                'account_status' => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            ['user_id'],
            ['first_name', 'last_name', 'mobile_number', 'account_status', 'updated_at'],
        );

        // ── Agent application ─────────────────────────────────────────────────

        $personalInfo = json_encode([
            'first_name'         => 'Test',
            'last_name'          => 'Agent',
            'national_id_number' => '111111111',
            'national_id_type'   => 'national_id',
            'date_of_birth'      => '1990-01-01',
            'phone'              => self::PHONE,
            'location_code'      => 'LOC001',
            'address'            => ['street' => '123 Test Street', 'city' => 'Gaborone', 'country' => 'BW'],
        ]);

        $applicationId = $platform->table('agent.agent_applications')
            ->where('user_id', $userId)
            ->value('id');

        if ($applicationId === null) {
            $applicationId = (string) Uuid::uuid7();
            $platform->table('agent.agent_applications')->insert([
                'id'              => $applicationId,
                'user_id'         => $userId,
                'agent_type'      => 'individual',
                'status'          => 'approved',
                'personal_info'   => $personalInfo,
                'business_info'   => null,
                'kyc_result'      => 'passed',
                'approved_at'     => $now,
                'submitted_at'    => $now,
                'correlation_id'  => (string) Uuid::uuid7(),
                'idempotency_key' => 'dev-agent-seeder-' . $userId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        } else {
            $platform->table('agent.agent_applications')
                ->where('id', $applicationId)
                ->update([
                    'status'        => 'approved',
                    'personal_info' => $personalInfo,
                    'kyc_result'    => 'passed',
                    'approved_at'   => $now,
                    'updated_at'    => $now,
                ]);
        }

        // ── agent.agents ──────────────────────────────────────────────────────

        $agentExists = $platform->table('agent.agents')
            ->where('user_id', $userId)
            ->exists();

        if (!$agentExists) {
            $platform->table('agent.agents')->insert([
                'id'             => (string) Uuid::uuid7(),
                'application_id' => $applicationId,
                'user_id'        => $userId,
                'agent_type'     => 'individual',
                'status'         => 'active',
                'approved_by'    => (string) Uuid::uuid7(),
                'approved_at'    => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } else {
            $platform->table('agent.agents')
                ->where('user_id', $userId)
                ->update(['status' => 'active', 'updated_at' => $now]);
        }

        $this->command?->info('DevAgentSeeder: phone fingerprint + profile + role + agent records added for ' . self::PHONE);
    }
}
