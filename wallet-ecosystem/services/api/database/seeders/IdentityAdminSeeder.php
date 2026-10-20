<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

/**
 * Local-development ADMIN account for the Admin web app (apps/admin).
 *
 * Provisions the three rows the /v1/auth/login password flow requires:
 *   - identity.users        — actor_type = ADMIN, status = active
 *   - identity.credentials  — credential_type = password (bcrypt hash)
 *   - identity.devices      — SHA-256('admin-web') pre-trusted, so the first
 *                             login does not trip device verification (IDENTITY_023)
 *
 * The Admin web client hard-codes device_fingerprint = 'admin-web'
 * (see apps/admin/src/modules/auth/api/auth.ts).
 *
 * Idempotent: safe to re-run; the password hash and device trust are refreshed.
 *
 * Credentials:
 *   email:    admin@olekard.local
 *   password: Admin123!
 *
 * NOT for production — guarded to non-production environments only.
 */
final class IdentityAdminSeeder extends Seeder
{
    private const EMAIL = 'admin@olekard.local';
    private const PASSWORD = 'Admin123!';
    private const DEVICE_FINGERPRINT_RAW = 'admin-web';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('IdentityAdminSeeder skipped in production.');

            return;
        }

        $now = now();
        $identity = DB::connection('identity');

        $userId = $identity->table('identity.users')
            ->where('email', self::EMAIL)
            ->value('id');

        if ($userId === null) {
            $userId = (string) Uuid::uuid7();
            $identity->table('identity.users')->insert([
                'id'                    => $userId,
                'mobile_number'         => null,
                'email'                 => self::EMAIL,
                'status'                => 'active',
                'actor_type'            => 'ADMIN',
                'failed_attempt_count'  => 0,
                'lockout_episode_count' => 0,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        } else {
            $identity->table('identity.users')
                ->where('id', $userId)
                ->update([
                    'status'               => 'active',
                    'actor_type'           => 'ADMIN',
                    'lock_reason'          => null,
                    'locked_at'            => null,
                    'failed_attempt_count' => 0,
                    'updated_at'           => $now,
                ]);
        }

        // Password credential (unique on user_id + credential_type).
        $credentialId = $identity->table('identity.credentials')
            ->where('user_id', $userId)
            ->where('credential_type', 'password')
            ->value('id');

        $credentialRow = [
            'credential_hash'      => Hash::make(self::PASSWORD),
            'status'               => 'active',
            'failed_attempt_count' => 0,
            'expires_at'           => null,
            'updated_at'           => $now,
        ];

        if ($credentialId === null) {
            $identity->table('identity.credentials')->insert($credentialRow + [
                'id'              => (string) Uuid::uuid7(),
                'user_id'         => $userId,
                'credential_type' => 'password',
                'created_at'      => $now,
            ]);
        } else {
            $identity->table('identity.credentials')
                ->where('id', $credentialId)
                ->update($credentialRow);
        }

        // Pre-trusted device (unique on user_id + device_fingerprint).
        $fingerprintHash = hash('sha256', self::DEVICE_FINGERPRINT_RAW);
        $deviceId = $identity->table('identity.devices')
            ->where('user_id', $userId)
            ->where('device_fingerprint', $fingerprintHash)
            ->value('id');

        $deviceRow = [
            'device_name'  => 'Admin Web (seeded)',
            'platform'     => 'web',
            'status'       => 'trusted',
            'trusted_at'   => $now,
            'last_seen_at' => $now,
            'updated_at'   => $now,
        ];

        if ($deviceId === null) {
            $identity->table('identity.devices')->insert($deviceRow + [
                'id'                 => (string) Uuid::uuid7(),
                'user_id'            => $userId,
                'device_fingerprint' => $fingerprintHash,
                'created_at'         => $now,
            ]);
        } else {
            $identity->table('identity.devices')
                ->where('id', $deviceId)
                ->update($deviceRow);
        }

        $this->command?->info(sprintf(
            'Seeded ADMIN login — email: %s  password: %s',
            self::EMAIL,
            self::PASSWORD,
        ));
    }
}
