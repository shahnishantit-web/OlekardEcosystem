<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PlatformConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $configs = [
            // Active values
            ['key' => 'platform.currencies.supported', 'value' => json_encode(['BWP', 'USD', 'EUR', 'ZAR', 'CNY', 'USDT']), 'type' => 'json', 'is_tbd' => false],
            ['key' => 'platform.currency.primary',     'value' => 'BWP',  'type' => 'string', 'is_tbd' => false],
            ['key' => 'idempotency.ttl_seconds',       'value' => '86400', 'type' => 'integer', 'is_tbd' => false],
            ['key' => 'events.max_attempts.default',   'value' => '5',    'type' => 'integer', 'is_tbd' => false],
            ['key' => 'events.bus_driver',             'value' => 'in_process', 'type' => 'string', 'is_tbd' => false],
            // Payment threshold — BWP 200 in minor units (T044 P03)
            ['key' => 'payment.refund_approval_threshold', 'value' => '20000', 'type' => 'integer', 'is_tbd' => false],
            // P04: Quote TTL in seconds (0 = always revalidate; default 300 = 5 minutes)
            ['key' => 'payment.quote_ttl_seconds', 'value' => '300', 'type' => 'integer', 'is_tbd' => false],
            // P05: Installed adapter allowlist — JSON array of permitted adapter_identifier values
            ['key' => 'payment.adapter_allowlist', 'value' => '["olekard_wallet","mock_bank"]', 'type' => 'json', 'is_tbd' => false],
            // TBD items — seeded as null per spec; NEVER invent defaults
            ['key' => 'mfa.step_up_threshold',                   'value' => null, 'type' => 'null', 'is_tbd' => true],
            ['key' => 'provider.payment_processor',              'value' => null, 'type' => 'null', 'is_tbd' => true],
            ['key' => 'reconciliation.tolerance',                 'value' => null, 'type' => 'null', 'is_tbd' => true],
            ['key' => 'events.outbox.processing_timeout_seconds', 'value' => null, 'type' => 'null', 'is_tbd' => true],
            // E26 PII retention — 0 = disabled (legal/compliance team sets actual values per jurisdiction)
            ['key' => 'pii.retention_days.identity',    'value' => '0', 'type' => 'integer', 'is_tbd' => true],
            ['key' => 'pii.retention_days.kyc',         'value' => '0', 'type' => 'integer', 'is_tbd' => true],
            ['key' => 'pii.retention_days.transaction',  'value' => '0', 'type' => 'integer', 'is_tbd' => true],
        ];

        foreach ($configs as $config) {
            DB::connection('platform')->table('platform.configuration_values')->upsert(
                [
                    'id'           => (string) Uuid::uuid7(),
                    'config_key'   => $config['key'],
                    'config_value' => $config['value'],
                    'value_type'   => $config['type'],
                    'status'       => 'active',
                    'version'      => 1,
                    'is_tbd'       => $config['is_tbd'],
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ],
                ['config_key', 'version'],
                ['config_value', 'value_type', 'is_tbd', 'updated_at'],
            );
        }
    }
}
