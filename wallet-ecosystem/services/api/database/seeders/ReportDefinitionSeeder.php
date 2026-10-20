<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Reporting\Infrastructure\Models\ReportDefinitionModel;
use Illuminate\Database\Seeder;

final class ReportDefinitionSeeder extends Seeder
{
    private const FILTER_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'date_range_start' => ['type' => 'string', 'format' => 'date'],
            'date_range_end'   => ['type' => 'string', 'format' => 'date'],
        ],
        'required' => ['date_range_start', 'date_range_end'],
    ];

    public function run(): void
    {
        $definitions = [
            [
                'type'                => 'user-report',
                'name'                => 'User Report',
                'description'         => 'User registration, KYC tiers, and account status summary.',
                'required_permission' => 'admin.read',
                'pii_sensitive'       => true,
            ],
            [
                'type'                => 'wallet-report',
                'name'                => 'Wallet Report',
                'description'         => 'Wallet balances, currencies, and statuses.',
                'required_permission' => 'admin.read',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'transaction-report',
                'name'                => 'Transaction Report',
                'description'         => 'Transaction ledger export with type, amount, and status breakdown.',
                'required_permission' => 'admin.read',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'agent-report',
                'name'                => 'Agent Report',
                'description'         => 'Agent performance, float balances, and transaction volumes.',
                'required_permission' => 'admin.read',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'commission-report',
                'name'                => 'Commission Report',
                'description'         => 'Agent commission earnings by status and settlement date.',
                'required_permission' => 'admin.read',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'kyc-report',
                'name'                => 'KYC Report',
                'description'         => 'KYC verification results and document review summary.',
                'required_permission' => 'compliance.reports',
                'pii_sensitive'       => true,
            ],
            [
                'type'                => 'reconciliation-report',
                'name'                => 'Reconciliation Report',
                'description'         => 'Transaction matching status and variance breakdown.',
                'required_permission' => 'finance.reports',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'settlement-report',
                'name'                => 'Settlement Report',
                'description'         => 'Settlement batch summary with participant count and totals.',
                'required_permission' => 'finance.reports',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'risk-report',
                'name'                => 'Risk Report',
                'description'         => 'AML flags, velocity breaches, and active user restrictions.',
                'required_permission' => 'compliance.reports',
                'pii_sensitive'       => false,
            ],
            [
                'type'                => 'financial-summary',
                'name'                => 'Financial Summary Report',
                'description'         => 'Per-currency inflow, outflow, fees, net position with balance validation.',
                'required_permission' => 'finance.reports',
                'pii_sensitive'       => false,
            ],
        ];

        foreach ($definitions as $data) {
            ReportDefinitionModel::updateOrCreate(
                ['type' => $data['type']],
                array_merge($data, [
                    'filter_schema' => self::FILTER_SCHEMA,
                    'is_active'     => true,
                ])
            );
        }
    }
}
