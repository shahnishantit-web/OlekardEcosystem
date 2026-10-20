<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Supplements CustomerAgentSeeder with the extra rows the Flutter customer app login
 * flow requires but that the base seeder omits:
 *
 *   - identity.devices      — phone-specific pre-trusted fingerprint
 *                             sha256("mobile-customer-+26771000001")
 *   - identity.user_roles   — 'customer' role assignment
 *   - profile.user_profiles — first_name / last_name / mobile_number
 *
 * CustomerAgentSeeder MUST run first (identity.users + credentials already present).
 *
 * The customer login controller uses rawDeviceFingerprint = "mobile-customer-{phone}",
 * which matches the fingerprint seeded here.
 *
 * NOT for production — guarded to non-production environments only.
 */
final class DevCustomerSeeder extends Seeder
{
    private const PHONE                  = '+26771000001';
    private const DEVICE_FINGERPRINT_RAW = 'mobile-customer-+26771000001';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DevCustomerSeeder skipped in production.');
            return;
        }

        $now      = now();
        $identity = DB::connection('identity');

        $userId = $identity->table('identity.users')
            ->where('mobile_number', self::PHONE)
            ->value('id');

        if ($userId === null) {
            $this->command?->warn('DevCustomerSeeder: user not found — run CustomerAgentSeeder first.');
            return;
        }

        // ── Phone-specific pre-trusted device ─────────────────────────────────

        $fingerprintHash = hash('sha256', self::DEVICE_FINGERPRINT_RAW);
        $deviceRow = [
            'device_name'  => 'Customer App — phone fingerprint (seeded)',
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

        // ── Customer role assignment ──────────────────────────────────────────

        $roleId = $identity->table('identity.roles')
            ->where('slug', 'customer')
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
                'last_name'      => 'Customer',
                'mobile_number'  => self::PHONE,
                'account_status' => 'active',
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            ['user_id'],
            ['first_name', 'last_name', 'mobile_number', 'account_status', 'updated_at'],
        );

        $this->command?->info('DevCustomerSeeder: phone fingerprint + profile + role added for ' . self::PHONE);
    }
}
