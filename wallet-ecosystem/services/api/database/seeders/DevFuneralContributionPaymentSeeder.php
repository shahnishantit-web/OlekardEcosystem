<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local-only mock bank configuration for Funeral Assistance contributions. */
final class DevFuneralContributionPaymentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Funeral contribution payment fixtures are local/testing only.');
        }

        $payment = DB::connection('payment');
        $now = now();
        $provider = $payment->table('payment.payment_providers')->where('code', 'mock_bank')->first();
        $providerId = $provider?->id ?? (string) Str::uuid7();

        $payment->table('payment.payment_providers')->updateOrInsert(['id' => $providerId], [
            'code' => 'mock_bank',
            'display_name' => 'Development Mock Bank (local only)',
            'adapter_identifier' => 'mock_bank',
            'adapter_version' => '1.0.0',
            'environment' => 'mock',
            'is_enabled' => true,
            'is_suspended' => false,
            'is_default' => false,
            'sort_order' => 10,
            'bank_lifecycle_status' => 'active',
            'supported_countries' => json_encode(['BW'], JSON_THROW_ON_ERROR),
            'description' => 'Local simulator for paid event tickets and funeral contributions. No real payment is processed.',
            'metadata_version' => 1,
            'updated_at' => $now,
            'created_at' => $provider?->created_at ?? $now,
        ]);

        $account = $payment->table('payment.payment_provider_accounts')
            ->where('provider_id', $providerId)->first();
        $accountId = $account?->id ?? (string) Str::uuid7();
        $supportedPurposes = ['paid_event_ticket', 'funeral_contribution'];

        $payment->table('payment.payment_provider_accounts')->updateOrInsert(['id' => $accountId], [
            'provider_id' => $providerId,
            'merchant_account_reference' => 'LOCAL-DEMO-BANK-001',
            'callback_secret_reference' => 'PAYMENT_MOCK_BANK_CALLBACK_SECRET',
            'supported_currencies' => json_encode(['BWP'], JSON_THROW_ON_ERROR),
            'supported_payment_methods' => json_encode(['bank_transfer'], JSON_THROW_ON_ERROR),
            'min_amount_minor' => 1,
            'max_amount_minor' => 999999999,
            'enabled_organisations' => json_encode([], JSON_THROW_ON_ERROR),
            // Keep module scope unrestricted: attempt-time validation accepts only
            // purpose-scoped local mock-bank accounts.
            'enabled_modules' => json_encode([], JSON_THROW_ON_ERROR),
            'enabled_purpose_types' => json_encode($supportedPurposes, JSON_THROW_ON_ERROR),
            'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
            'capability_flags' => json_encode(['supports_refund' => true], JSON_THROW_ON_ERROR),
            'fee_rule_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'daily_limit_minor' => null,
            'is_active' => true,
            'config_version' => ((int) ($account?->config_version ?? 0)) + 1,
            'display_label' => 'Development demo bank',
            'display_description' => 'Local payment simulator; no real money is moved.',
            'display_icon_key' => 'icon.payment.bank',
            'display_badge' => 'LOCAL DEMO',
            'account_lifecycle_status' => 'active',
            'activated_at' => $account?->activated_at ?? $now,
            'created_at' => $account?->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $consumerOption = $payment->table('payment.consumer_payment_options')
            ->where('account_id', $accountId)->where('payment_method', 'bank_redirect')->first();
        $payment->table('payment.consumer_payment_options')->updateOrInsert(
            ['account_id' => $accountId, 'payment_method' => 'bank_redirect'],
            [
                'id' => $consumerOption?->id ?? (string) Str::uuid7(),
                'display_label' => 'Development demo bank',
                'display_order' => 1,
                'supported_purpose_types' => json_encode($supportedPurposes, JSON_THROW_ON_ERROR),
                'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $consumerOption?->created_at ?? $now,
                'updated_at' => $now,
            ],
        );

        $integration = DB::connection('platform')->table('integration.integrations')
            ->where('provider_type', 'payment')->where('provider_slug', 'mock-bank-local')->first();
        DB::connection('platform')->table('integration.integrations')->updateOrInsert(
            ['provider_type' => 'payment', 'provider_slug' => 'mock-bank-local'],
            [
                'id' => $integration?->id ?? (string) Str::uuid7(),
                'display_name' => 'Development Mock Bank (local only)',
                'credentials_reference' => 'local.mock_bank',
                'endpoint_override' => null,
                'status' => 'active',
                'is_default' => false,
                'configuration' => json_encode([
                    'payment_provider_account_id' => $accountId,
                    'payment_provider_code' => 'mock_bank',
                    'environment' => 'mock',
                ], JSON_THROW_ON_ERROR),
                'created_by_user_id' => null,
                'created_at' => $integration?->created_at ?? $now,
                'updated_at' => $now,
            ],
        );

        $this->command?->info('Enabled the local demo bank for paid event tickets and funeral contributions.');
    }
}
