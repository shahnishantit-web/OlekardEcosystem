<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

/**
 * Local-development CUSTOMER and AGENT demo accounts for Customer & Agent mobile apps.
 *
 * Credentials:
 *   Customer:
 *     Phone:    +26771000001
 *     Email:    customer@olekard.local
 *     PIN:      123456
 *
 *   Agent:
 *     Phone:    +26771000002
 *     Email:    agent@olekard.local
 *     PIN:      123456
 */
final class CustomerAgentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('CustomerAgentSeeder skipped in production.');
            return;
        }

        $now = now();
        $identity = DB::connection('identity');

        $usersToSeed = [
            [
                'phone'      => '+26771000001',
                'email'      => 'customer@olekard.local',
                'actor_type' => 'CUSTOMER',
                'pin'        => '123456',
                'device_name'=> 'Customer Mobile (seeded)',
            ],
            [
                'phone'      => '+26771000002',
                'email'      => 'agent@olekard.local',
                'actor_type' => 'AGENT',
                'pin'        => '123456',
                'device_name'=> 'Agent Mobile (seeded)',
            ],
        ];

        foreach ($usersToSeed as $seedData) {
            $userId = $identity->table('identity.users')
                ->where('mobile_number', $seedData['phone'])
                ->orWhere('email', $seedData['email'])
                ->value('id');

            if ($userId === null) {
                $userId = (string) Uuid::uuid7();
                $identity->table('identity.users')->insert([
                    'id'                    => $userId,
                    'mobile_number'         => $seedData['phone'],
                    'email'                 => $seedData['email'],
                    'status'                => 'active',
                    'actor_type'            => $seedData['actor_type'],
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
                        'actor_type'           => $seedData['actor_type'],
                        'lock_reason'          => null,
                        'locked_at'            => null,
                        'failed_attempt_count' => 0,
                        'updated_at'           => $now,
                    ]);
            }

            // PIN Credential
            $credentialId = $identity->table('identity.credentials')
                ->where('user_id', $userId)
                ->where('credential_type', 'pin')
                ->value('id');

            $credentialRow = [
                'credential_hash'      => Hash::make($seedData['pin']),
                'status'               => 'active',
                'failed_attempt_count' => 0,
                'expires_at'           => null,
                'updated_at'           => $now,
            ];

            if ($credentialId === null) {
                $identity->table('identity.credentials')->insert($credentialRow + [
                    'id'              => (string) Uuid::uuid7(),
                    'user_id'         => $userId,
                    'credential_type' => 'pin',
                    'created_at'      => $now,
                ]);
            } else {
                $identity->table('identity.credentials')
                    ->where('id', $credentialId)
                    ->update($credentialRow);
            }

            // Pre-trust fallback device fingerprint ('unknown') to avoid IDENTITY_023 on unconfigured devices
            $fingerprints = ['unknown', 'customer-app', 'agent-app', 'emulator'];
            foreach ($fingerprints as $fpRaw) {
                $fpHash = hash('sha256', $fpRaw);
                $deviceId = $identity->table('identity.devices')
                    ->where('user_id', $userId)
                    ->where('device_fingerprint', $fpHash)
                    ->value('id');

                $deviceRow = [
                    'device_name'  => $seedData['device_name'],
                    'platform'     => 'mobile',
                    'status'       => 'trusted',
                    'trusted_at'   => $now,
                    'last_seen_at' => $now,
                    'updated_at'   => $now,
                ];

                if ($deviceId === null) {
                    $identity->table('identity.devices')->insert($deviceRow + [
                        'id'                 => (string) Uuid::uuid7(),
                        'user_id'            => $userId,
                        'device_fingerprint' => $fpHash,
                        'created_at'         => $now,
                    ]);
                } else {
                    $identity->table('identity.devices')
                        ->where('id', $deviceId)
                        ->update($deviceRow);
                }
            }

            $this->command?->info(sprintf(
                'Seeded %s login — Phone: %s  PIN: %s',
                $seedData['actor_type'],
                $seedData['phone'],
                $seedData['pin'],
            ));
        }
    }
}
