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
use Illuminate\Support\Str;

/** Local app fixtures: domain-provisioned zero-balance wallets; no balance writes. */
final class DevCustomerAppSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('DevCustomerAppSeeder is local/testing only.');
        }
        $identity = DB::connection('identity');
        $customer = $identity->table('identity.users')->where('mobile_number', '+26771000001')->first();
        $adminId = $identity->table('identity.users')->where('email', 'admin@olekard.local')->value('id');
        if (! $customer || ! $adminId) throw new \RuntimeException('Seed the local customer and administrator first.');
        $roleId = $identity->table('identity.roles')->where('slug', 'customer')->whereNull('deleted_at')->value('id');
        if (! $roleId) throw new \RuntimeException('The customer role is required.');
        // Explicit consumer permissions only. Ownership remains enforced by each API.
        $permissions = ['wallet.read', 'transaction.read', 'transaction.receipt.read',
            'transfer.initiate', 'transfer.read', 'funding.initiate', 'funding.read',
            'withdrawal.initiate', 'withdrawal.read', 'service.list', 'service.pay',
            'payment.intents.read', 'payment.intents.manage'];
        $identity->transaction(function () use ($identity, $permissions, $roleId, $adminId): void {
            foreach ($permissions as $slug) {
                $permissionId = $identity->table('identity.permissions')->where('slug', $slug)->whereNull('deleted_at')->value('id');
                if (! $permissionId) {
                    $permissionId = (string) Str::uuid7();
                    $identity->table('identity.permissions')->insert([
                        'id' => $permissionId, 'slug' => $slug,
                        'display_name' => Str::headline(str_replace('.', ' ', $slug)),
                        'module' => Str::before($slug, '.'), 'is_system_permission' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if (! $identity->table('identity.role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
                    $identity->table('identity.role_permissions')->insert([
                        'id' => (string) Str::uuid7(), 'role_id' => $roleId, 'permission_id' => $permissionId,
                        'created_by_actor_id' => $adminId, 'created_at' => now(),
                    ]);
                }
            }
        });
        app(PermissionResolverService::class)->invalidateCache($customer->id);
        if (! $identity->table('identity.users')->where('mobile_number', '+26771000003')->exists()) {
            $recipient = app(\App\Modules\Identity\Domain\Contracts\UserRepositoryInterface::class)->create([
                'mobile_number' => '+26771000003', 'status' => 'active', 'actor_type' => 'CUSTOMER',
                'default_role_id' => $roleId,
            ]);
            app(\App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface::class)->create([
                'user_id' => $recipient->id, 'credential_type' => 'pin',
                'credential_hash' => \Illuminate\Support\Facades\Hash::make('123456'), 'status' => 'active',
            ]);
            $identity->table('identity.user_roles')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => $recipient->id, 'role_id' => $roleId,
                'created_by_actor_id' => $adminId, 'created_at' => now(),
            ]);
        }
        foreach (['+26771000001', '+26771000002', '+26771000003'] as $phone) {
            $userId = $identity->table('identity.users')->where('mobile_number', $phone)->value('id');
            if (! $userId) continue;
            DB::connection('platform')->transaction(function () use ($userId, $adminId): void {
                $correlation = (string) Str::uuid7();
                $wallet = WalletModel::where('owner_id', $userId)->where('status', '!=', 'closed')->first();
                if (! $wallet) {
                    $wallet = app(WalletProvisioningService::class)->provisionForUser($userId, $correlation, $adminId);
                }
                if ($wallet->status === WalletStatus::Pending) {
                    $wallet = app(WalletService::class)->transitionStatus($wallet->id, WalletStatus::Active,
                        'Local development app fixture', $adminId, $correlation);
                }
                foreach ($wallet->pockets as $pocket) {
                    app(LedgerAccountService::class)->registerAccount($pocket->ledger_account_reference,
                        'liability', $pocket->currency_code, $pocket->id, 'customer_pocket', $adminId, $correlation);
                }
            });
        }
        foreach (['electricity', 'water', 'airtime', 'internet'] as $category) {
            $name = 'Development Mock '.ucfirst($category);
            if (! \App\Modules\Service\Infrastructure\Models\ServiceModel::where('name', $name)->exists()) {
                app(\App\Modules\Service\Application\Services\ServiceCatalogService::class)->create([
                    'name' => $name, 'category' => $category, 'provider_code' => 'mock',
                    'description' => 'Local development service fixture; no live provider is charged.',
                    'currency_code' => 'BWP', 'min_amount' => 100, 'max_amount' => 500000,
                    'fee_type' => 'flat', 'fee_value' => 0, 'required_fields' => ['customer_reference'],
                ], $adminId, (string) Str::uuid7());
            }
        }
        // Explicit local simulator configuration; never runs in production.
        $fixtures = [
            'withdrawal.channel.bank_transfer.available_currencies' => 'BWP',
            'withdrawal.channel.mobile_money.available_currencies' => 'BWP',
        ];
        foreach (['payout_transit' => 'liability', 'fee_revenue' => 'income'] as $kind => $accountType) {
            $ref = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, "dev.mock-bank.{$kind}.BWP")->toString();
            app(LedgerAccountService::class)->registerAccount($ref, $accountType, 'BWP', $adminId,
                'system_revenue', $adminId, (string) Str::uuid7());
            $fixtures["ledger.{$kind}.BWP"] = $ref;
        }
        foreach ($fixtures as $key => $value) {
            if (! DB::connection('platform')->table('platform.configuration_values')->where('config_key', $key)->exists()) {
                DB::connection('platform')->table('platform.configuration_values')->insert([
                    'id' => (string) Str::uuid7(), 'config_key' => $key, 'config_value' => $value,
                    'value_type' => 'string', 'status' => 'active', 'is_tbd' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                app(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class)->invalidate($key);
            }
        }
        $this->command?->info('Customer app permissions, demo wallets, services and mock-bank fixtures provisioned.');
    }
}
