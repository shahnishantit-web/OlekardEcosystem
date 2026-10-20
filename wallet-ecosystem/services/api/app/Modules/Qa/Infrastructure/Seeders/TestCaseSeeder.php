<?php

declare(strict_types=1);

namespace App\Modules\Qa\Infrastructure\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TestCaseSeeder extends Seeder
{
    private const CATEGORIES = [
        'unit_auth'            => 'Unit test authentication',
        'integration_auth'     => 'Integration test authentication',
        'api_authorization'    => 'API authorization testing',
        'wallet_balance'       => 'Wallet balance testing',
        'ledger_balance'       => 'Ledger balance testing',
        'transfer'             => 'Transfer testing',
        'duplicate_transaction'=> 'Duplicate transaction testing',
        'callback'             => 'Callback testing',
        'payment_failure'      => 'Payment failure testing',
        'reconciliation'       => 'Reconciliation testing',
        'kyc'                  => 'KYC testing',
        'agent'                => 'Agent testing',
        'security'             => 'Security testing',
        'performance'          => 'Performance testing',
        'load'                 => 'Load testing',
        'disaster_recovery'    => 'Disaster recovery testing',
        'uat'                  => 'UAT',
        'regression'           => 'Regression testing',
    ];

    private const SCENARIO_TYPES = [
        'happy_path'    => 'Happy-path test with valid data.',
        'validation'    => 'Required-field and invalid-format validation test.',
        'authorization' => 'Unauthorized-role/access test.',
        'boundary'      => 'Boundary/limit test where applicable.',
        'failure'       => 'Failure/timeout/provider-error test where applicable.',
        'idempotency'   => 'Duplicate/idempotency test where applicable.',
        'audit'         => 'Audit/event verification.',
        'regression'    => 'Regression test for dependent modules.',
    ];

    public function run(): void
    {
        $now = now()->toDateTimeString();

        foreach (self::CATEGORIES as $category => $categoryLabel) {
            foreach (self::SCENARIO_TYPES as $scenarioType => $scenarioDescription) {
                $code = "qa.{$category}.{$scenarioType}";
                $name = "{$categoryLabel} — {$scenarioDescription}";

                DB::connection('platform')->table('qa.test_cases')->updateOrInsert(
                    ['code' => $code],
                    [
                        'id'              => (string) Uuid::uuid7(),
                        'code'            => $code,
                        'category'        => $category,
                        'name'            => $name,
                        'description'     => $scenarioDescription,
                        'scenario_type'   => $scenarioType,
                        'expected_outcome'=> null,
                        'is_active'       => true,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]
                );
            }
        }

        $this->command?->info('QA TestCaseSeeder: 144 test cases seeded (18 categories × 8 scenario types).');
    }
}
