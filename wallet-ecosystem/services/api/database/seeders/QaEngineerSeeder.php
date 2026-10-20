<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

/**
 * Creates the qa@olekard.local QA engineer user with qa.execute permission.
 * Idempotent: safe to run multiple times.
 * Required by quickstart SC-1 and SC-3.
 */
final class QaEngineerSeeder extends Seeder
{
    private const QA_EMAIL    = 'qa@olekard.local';
    private const QA_PASSWORD = 'Qa123!';

    public function run(): void
    {
        $existing = DB::connection('identity')
            ->table('identity.users')
            ->where('email', self::QA_EMAIL)
            ->first();

        if ($existing) {
            $userId = $existing->id;
        } else {
            $userId = (string) Uuid::uuid7();
            DB::connection('identity')->table('identity.users')->insert([
                'id'           => $userId,
                'email'        => self::QA_EMAIL,
                'mobile_number'=> '+26770000099',
                'actor_type'   => 'ADMIN',
                'status'       => 'active',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Upsert credential
        DB::connection('identity')->table('identity.credentials')->updateOrInsert(
            ['user_id' => $userId, 'credential_type' => 'password'],
            [
                'id'              => (string) Uuid::uuid7(),
                'user_id'         => $userId,
                'credential_type' => 'password',
                'credential_hash' => Hash::make(self::QA_PASSWORD),
                'is_active'       => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]
        );

        // Ensure qa.execute permission exists
        $permExists = DB::connection('identity')
            ->table('identity.permissions')
            ->where('slug', 'qa.execute')
            ->exists();

        if (!$permExists) {
            DB::connection('identity')->table('identity.permissions')->insert([
                'id'          => (string) Uuid::uuid7(),
                'slug'        => 'qa.execute',
                'name'        => 'QA Execute',
                'description' => 'Trigger and view QA test runs',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Create qa_engineer role if missing and assign permission + user
        $roleRow = DB::connection('identity')
            ->table('identity.roles')
            ->where('slug', 'qa_engineer')
            ->first();

        if (!$roleRow) {
            $roleId = (string) Uuid::uuid7();
            DB::connection('identity')->table('identity.roles')->insert([
                'id'          => $roleId,
                'slug'        => 'qa_engineer',
                'name'        => 'QA Engineer',
                'description' => 'Can trigger and view QA validation runs',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        } else {
            $roleId = $roleRow->id;
        }

        $permId = DB::connection('identity')
            ->table('identity.permissions')
            ->where('slug', 'qa.execute')
            ->value('id');

        DB::connection('identity')->table('identity.role_permissions')->updateOrInsert(
            ['role_id' => $roleId, 'permission_id' => $permId],
            ['role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now()]
        );

        DB::connection('identity')->table('identity.user_roles')->updateOrInsert(
            ['user_id' => $userId, 'role_id' => $roleId],
            ['user_id' => $userId, 'role_id' => $roleId, 'created_at' => now()]
        );

        $this->command?->info("QaEngineerSeeder: qa@olekard.local ready with qa.execute permission.");
    }
}
