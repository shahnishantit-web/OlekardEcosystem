<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds realistic payment provider + account data for local development.
 * Safe to re-run — uses upsert on provider code / provider_id.
 */
final class DevPaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DevPaymentProviderSeeder only runs in local/testing.');
            return;
        }

        $db  = DB::connection('payment');
        $now = now();

        $providers = [
            [
                'code'               => 'olekard_wallet',
                'display_name'       => 'OleKard Wallet',
                'adapter_identifier' => 'olekard_wallet',
                'adapter_version'    => '1.0.0',
                'environment'        => 'production',
                'is_enabled'         => true,
                'is_suspended'       => false,
                'is_default'         => true,
                'sort_order'         => 10,
                'accounts' => [
                    [
                        'merchant_account_reference' => 'OLK-WALLET-PRIMARY',
                        'supported_currencies'       => json_encode(['BWP']),
                        'supported_payment_methods'  => json_encode(['wallet_transfer']),
                        'min_amount_minor'           => 100,
                        'max_amount_minor'           => 10000000,
                        'daily_limit_minor'          => null,
                        'enabled_organisations'      => json_encode([]),
                        'enabled_modules'            => json_encode(['funeral_contribution', 'wallet_transfer', 'bill_payment']),
                        'country_restrictions'       => json_encode([]),
                        'callback_url'               => null,
                        'callback_secret_reference'  => null,
                        'capability_flags'           => json_encode(['supports_refund' => true, 'supports_qr' => false]),
                        'fee_rule_ids'               => json_encode([]),
                        'enabled_purpose_types'      => json_encode(['funeral_contribution', 'peer_transfer', 'bill_payment']),
                        'is_active'                  => true,
                        'config_version'             => 3,
                        'display_description'        => 'Pay directly from your wallet balance',
                        'display_icon_key'           => 'icon.payment.wallet',
                        'display_badge'              => 'Recommended',
                    ],
                ],
            ],
            [
                'code'               => 'fnb_botswana',
                'display_name'       => 'First National Bank Botswana',
                'adapter_identifier' => 'fnb_bw',
                'adapter_version'    => '2.1.0',
                'environment'        => 'production',
                'is_enabled'         => true,
                'is_suspended'       => false,
                'is_default'         => false,
                'sort_order'         => 20,
                'accounts' => [
                    [
                        'merchant_account_reference' => 'FNB-MERCHANT-00412',
                        'supported_currencies'       => json_encode(['BWP']),
                        'supported_payment_methods'  => json_encode(['bank_transfer']),
                        'min_amount_minor'           => 500,
                        'max_amount_minor'           => 50000000,
                        'daily_limit_minor'          => 5000000,
                        'enabled_organisations'      => json_encode([]),
                        'enabled_modules'            => json_encode(['funeral_contribution', 'bill_payment']),
                        'country_restrictions'       => json_encode([]),
                        'callback_url'               => null,
                        'callback_secret_reference'  => 'secrets/payment/fnb/callback-hmac',
                        'capability_flags'           => json_encode(['supports_refund' => true, 'supports_qr' => false, 'supports_recurring' => false]),
                        'fee_rule_ids'               => json_encode([]),
                        'enabled_purpose_types'      => json_encode(['funeral_contribution', 'bill_payment']),
                        'is_active'                  => true,
                        'config_version'             => 7,
                        'display_description'        => 'Instant bank transfer from any FNB account',
                        'display_icon_key'           => 'icon.payment.fnb',
                        'display_badge'              => null,
                    ],
                ],
            ],
            [
                'code'               => 'stanchart_bw',
                'display_name'       => 'Standard Chartered Botswana',
                'adapter_identifier' => 'stanchart_bw',
                'adapter_version'    => '1.3.0',
                'environment'        => 'production',
                'is_enabled'         => true,
                'is_suspended'       => false,
                'is_default'         => false,
                'sort_order'         => 30,
                'accounts' => [
                    [
                        'merchant_account_reference' => 'SCB-BWP-MAIN-2024',
                        'supported_currencies'       => json_encode(['BWP']),
                        'supported_payment_methods'  => json_encode(['bank_transfer']),
                        'min_amount_minor'           => 1000,
                        'max_amount_minor'           => 100000000,
                        'daily_limit_minor'          => 20000000,
                        'enabled_organisations'      => json_encode([]),
                        'enabled_modules'            => json_encode(['funeral_contribution']),
                        'country_restrictions'       => json_encode([]),
                        'callback_url'               => null,
                        'callback_secret_reference'  => 'secrets/payment/stanchart/webhook-key',
                        'capability_flags'           => json_encode(['supports_refund' => true, 'supports_qr' => false]),
                        'fee_rule_ids'               => json_encode([]),
                        'enabled_purpose_types'      => json_encode(['funeral_contribution']),
                        'is_active'                  => true,
                        'config_version'             => 2,
                        'display_description'        => 'Bank transfer via Standard Chartered',
                        'display_icon_key'           => 'icon.payment.stanchart',
                        'display_badge'              => null,
                    ],
                ],
            ],
            [
                'code'               => 'orange_money_bw',
                'display_name'       => 'Orange Money Botswana',
                'adapter_identifier' => 'orange_money_bw',
                'adapter_version'    => '1.0.2',
                'environment'        => 'production',
                'is_enabled'         => false,
                'is_suspended'       => false,
                'is_default'         => false,
                'sort_order'         => 40,
                'accounts' => [
                    [
                        'merchant_account_reference' => 'ORANGE-PENDING-001',
                        'supported_currencies'       => json_encode(['BWP']),
                        'supported_payment_methods'  => json_encode(['mobile_money']),
                        'min_amount_minor'           => 100,
                        'max_amount_minor'           => 1000000,
                        'daily_limit_minor'          => 500000,
                        'enabled_organisations'      => json_encode([]),
                        'enabled_modules'            => json_encode([]),
                        'country_restrictions'       => json_encode([]),
                        'callback_url'               => null,
                        'callback_secret_reference'  => null,
                        'capability_flags'           => json_encode(['supports_refund' => false, 'supports_qr' => false]),
                        'fee_rule_ids'               => json_encode([]),
                        'enabled_purpose_types'      => json_encode([]),
                        'is_active'                  => false,
                        'config_version'             => 1,
                        'display_description'        => 'Mobile money — certification in progress',
                        'display_icon_key'           => 'icon.payment.orange',
                        'display_badge'              => 'Coming Soon',
                    ],
                ],
            ],
            [
                'code'               => 'absa_bw',
                'display_name'       => 'Absa Bank Botswana',
                'adapter_identifier' => 'absa_bw',
                'adapter_version'    => '2.0.0',
                'environment'        => 'production',
                'is_enabled'         => true,
                'is_suspended'       => true,
                'is_default'         => false,
                'sort_order'         => 50,
                'accounts' => [
                    [
                        'merchant_account_reference' => 'ABSA-BW-MERCHANT-007',
                        'supported_currencies'       => json_encode(['BWP']),
                        'supported_payment_methods'  => json_encode(['bank_transfer']),
                        'min_amount_minor'           => 500,
                        'max_amount_minor'           => 25000000,
                        'daily_limit_minor'          => 10000000,
                        'enabled_organisations'      => json_encode([]),
                        'enabled_modules'            => json_encode(['funeral_contribution', 'bill_payment']),
                        'country_restrictions'       => json_encode([]),
                        'callback_url'               => null,
                        'callback_secret_reference'  => 'secrets/payment/absa/webhook-hmac',
                        'capability_flags'           => json_encode(['supports_refund' => true, 'supports_qr' => false]),
                        'fee_rule_ids'               => json_encode([]),
                        'enabled_purpose_types'      => json_encode(['funeral_contribution', 'bill_payment']),
                        'is_active'                  => false,
                        'config_version'             => 12,
                        'display_description'        => 'Bank transfer via Absa Botswana — suspended pending API key rotation',
                        'display_icon_key'           => 'icon.payment.absa',
                        'display_badge'              => null,
                    ],
                ],
            ],
            [
                'code'               => 'mock_bank',
                'display_name'       => 'Development Mock Bank',
                'adapter_identifier' => 'mock_bank',
                'adapter_version'    => '1.0.0',
                'environment'        => 'mock',
                'is_enabled'         => false,
                'is_suspended'       => false,
                'is_default'         => false,
                'sort_order'         => 99,
                'accounts' => [
                    [
                        'merchant_account_reference' => 'MOCK-TEST-ACCT-001',
                        'supported_currencies'       => json_encode(['BWP', 'USD', 'ZAR']),
                        'supported_payment_methods'  => json_encode(['bank_transfer', 'card_payment']),
                        'min_amount_minor'           => 1,
                        'max_amount_minor'           => 999999999,
                        'daily_limit_minor'          => null,
                        'enabled_organisations'      => json_encode([]),
                        'enabled_modules'            => json_encode(['funeral_contribution', 'wallet_transfer', 'bill_payment']),
                        'country_restrictions'       => json_encode([]),
                        'callback_url'               => null,
                        'callback_secret_reference'  => null,
                        'capability_flags'           => json_encode(['supports_refund' => true, 'supports_qr' => true, 'supports_recurring' => true]),
                        'fee_rule_ids'               => json_encode([]),
                        'enabled_purpose_types'      => json_encode(['funeral_contribution', 'peer_transfer', 'bill_payment']),
                        'is_active'                  => false,
                        'config_version'             => 1,
                        'display_description'        => 'Always approves — development only',
                        'display_icon_key'           => null,
                        'display_badge'              => 'DEV ONLY',
                    ],
                ],
            ],
        ];

        foreach ($providers as $providerData) {
            $accounts = $providerData['accounts'];
            unset($providerData['accounts']);

            $existing = $db->table('payment.payment_providers')->where('code', $providerData['code'])->first();

            if ($existing) {
                $providerId = $existing->id;
                $db->table('payment.payment_providers')
                    ->where('id', $providerId)
                    ->update(array_merge($providerData, ['updated_at' => $now]));
                $this->command?->line("  Updated: {$providerData['display_name']}");
            } else {
                $providerId = (string) Str::uuid7();
                $db->table('payment.payment_providers')->insert(array_merge(
                    ['id' => $providerId, 'created_at' => $now, 'updated_at' => $now],
                    $providerData
                ));
                $this->command?->line("  Created: {$providerData['display_name']}");
            }

            foreach ($accounts as $accountData) {
                $existingAccount = $db->table('payment.payment_provider_accounts')
                    ->where('provider_id', $providerId)
                    ->first();

                if ($existingAccount) {
                    $db->table('payment.payment_provider_accounts')
                        ->where('id', $existingAccount->id)
                        ->update(array_merge($accountData, ['updated_at' => $now]));
                } else {
                    $db->table('payment.payment_provider_accounts')->insert(array_merge(
                        ['id' => (string) Str::uuid7(), 'provider_id' => $providerId,
                         'created_at' => $now, 'updated_at' => $now],
                        $accountData
                    ));
                }
            }
        }

        $this->command?->info('Payment providers seeded: 6 providers with accounts.');
    }
}
