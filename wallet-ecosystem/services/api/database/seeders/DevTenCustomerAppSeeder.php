<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Ledger\Application\Services\LedgerAccountService;
use App\Modules\Wallet\Application\Services\WalletProvisioningService;
use App\Modules\Wallet\Application\Services\WalletService;
use App\Modules\Wallet\Domain\Enums\WalletStatus;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Local-only customer app accounts with active, zero-balance wallets. */
final class DevTenCustomerAppSeeder extends Seeder
{
    private const FIRST_NUMBER = 11;
    private const CUSTOMER_COUNT = 10;
    private const PIN = '123456';

    /** @var list<string> */
    private const CUSTOMER_PERMISSIONS = [
        'wallet.read', 'wallet.balance.read', 'transaction.read', 'transaction.receipt.read',
        'transfer.initiate', 'transfer.read', 'funding.initiate', 'funding.read',
        'withdrawal.initiate', 'withdrawal.read', 'service.list', 'service.pay',
        'payment.intents.read', 'payment.intents.manage',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DevTenCustomerAppSeeder is local/testing only.');
        }

        $identity = DB::connection('identity');
        $adminId = $identity->table('identity.users')->where('email', 'admin@olekard.local')->value('id');
        $roleId = $identity->table('identity.roles')->where('slug', 'customer')->whereNull('deleted_at')->value('id');
        if (! $adminId || ! $roleId) {
            throw new \RuntimeException('Seed admin@olekard.local and the customer role before running this seeder.');
        }

        foreach (self::CUSTOMER_PERMISSIONS as $slug) {
            $permissionId = $identity->table('identity.permissions')->where('slug', $slug)->whereNull('deleted_at')->value('id');
            if (! $permissionId) {
                $permissionId = (string) Str::uuid7();
                $identity->table('identity.permissions')->insert([
                    'id' => $permissionId,
                    'slug' => $slug,
                    'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                    'module' => Str::before($slug, '.'),
                    'is_system_permission' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $identity->table('identity.role_permissions')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_by_actor_id' => $adminId,
                'created_at' => now(),
            ]);
        }

        $logins = [];
        for ($offset = 0; $offset < self::CUSTOMER_COUNT; $offset++) {
            $number = self::FIRST_NUMBER + $offset;
            $phone = sprintf('+267710000%02d', $number);
            $email = sprintf('demo.customer%02d@olekard.local', $offset + 1);
            $firstName = 'Demo';
            $lastName = sprintf('Customer %02d', $offset + 1);
            $now = now();

            $userId = $identity->table('identity.users')->where('mobile_number', $phone)->value('id');
            if ($userId === null) {
                $userId = (string) Uuid::uuid7();
                $identity->table('identity.users')->insert([
                    'id' => $userId,
                    'mobile_number' => $phone,
                    'email' => $email,
                    'status' => 'active',
                    'actor_type' => 'CUSTOMER',
                    'default_role_id' => $roleId,
                    'failed_attempt_count' => 0,
                    'lockout_episode_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $identity->table('identity.users')->where('id', $userId)->update([
                    'email' => $email,
                    'status' => 'active',
                    'actor_type' => 'CUSTOMER',
                    'default_role_id' => $roleId,
                    'lock_reason' => null,
                    'locked_at' => null,
                    'failed_attempt_count' => 0,
                    'updated_at' => $now,
                ]);
            }

            $credentialId = $identity->table('identity.credentials')
                ->where('user_id', $userId)->where('credential_type', 'pin')->value('id');
            $credential = [
                'credential_hash' => Hash::make(self::PIN),
                'status' => 'active',
                'failed_attempt_count' => 0,
                'expires_at' => null,
                'updated_at' => $now,
            ];
            if ($credentialId === null) {
                $identity->table('identity.credentials')->insert($credential + [
                    'id' => (string) Uuid::uuid7(),
                    'user_id' => $userId,
                    'credential_type' => 'pin',
                    'created_at' => $now,
                ]);
            } else {
                $identity->table('identity.credentials')->where('id', $credentialId)->update($credential);
            }

            $identity->table('identity.user_roles')->insertOrIgnore([
                'id' => (string) Uuid::uuid7(),
                'user_id' => $userId,
                'role_id' => $roleId,
                'created_by_actor_id' => $adminId,
                'created_at' => $now,
            ]);

            $fingerprint = 'mobile-customer-'.$phone;
            $fingerprintHash = hash('sha256', $fingerprint);
            $deviceId = $identity->table('identity.devices')->where('user_id', $userId)
                ->where('device_fingerprint', $fingerprintHash)->value('id');
            $device = [
                'device_name' => sprintf('Customer App demo %02d', $offset + 1),
                'platform' => 'mobile',
                'status' => 'trusted',
                'trusted_at' => $now,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];
            if ($deviceId === null) {
                $identity->table('identity.devices')->insert($device + [
                    'id' => (string) Uuid::uuid7(),
                    'user_id' => $userId,
                    'device_fingerprint' => $fingerprintHash,
                    'created_at' => $now,
                ]);
            } else {
                $identity->table('identity.devices')->where('id', $deviceId)->update($device);
            }

            DB::connection('profile')->table('profile.user_profiles')->upsert([[
                'id' => (string) Uuid::uuid7(),
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'mobile_number' => $phone,
                'account_status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['user_id'], ['first_name', 'last_name', 'mobile_number', 'account_status', 'updated_at']);

            $correlation = (string) Str::uuid7();
            DB::connection('platform')->transaction(function () use ($userId, $adminId, $correlation): void {
                $wallet = WalletModel::where('owner_id', $userId)->where('status', '!=', 'closed')->first();
                if (! $wallet) {
                    $wallet = app(WalletProvisioningService::class)->provisionForUser($userId, $correlation, $adminId);
                }
                if ($wallet->status === WalletStatus::Pending) {
                    $wallet = app(WalletService::class)->transitionStatus(
                        $wallet->id, WalletStatus::Active, 'Local demo customer fixture', $adminId, $correlation,
                    );
                }
                foreach ($wallet->pockets as $pocket) {
                    app(LedgerAccountService::class)->registerAccount(
                        $pocket->ledger_account_reference, 'liability', $pocket->currency_code,
                        $pocket->id, 'customer_pocket', $adminId, $correlation,
                    );
                }
            });

            app(PermissionResolverService::class)->invalidateCache((string) $userId);
            $logins[] = ['phone' => $phone, 'email' => $email];
        }

        $this->command?->table(['Customer', 'Phone', 'Email', 'PIN'], array_map(
            static fn (array $login, int $index): array => [
                sprintf('Demo Customer %02d', $index + 1), $login['phone'], $login['email'], self::PIN,
            ],
            $logins,
            array_keys($logins),
        ));
        $this->command?->info('10 local customer accounts and zero-balance wallets are ready.');
    }
}
