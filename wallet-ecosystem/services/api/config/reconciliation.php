<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reconciliation Module Configuration
    |--------------------------------------------------------------------------
    */

    'webhook_secret' => env('RECONCILIATION_WEBHOOK_SECRET', ''),

    'provider_file_adapter' => env(
        'RECONCILIATION_FILE_ADAPTER',
        \App\Modules\Reconciliation\Application\Adapters\CsvProviderFileAdapter::class
    ),

    'settlement_adapter' => env(
        'RECONCILIATION_SETTLEMENT_ADAPTER',
        \App\Modules\Reconciliation\Application\Adapters\NullSettlementAdapter::class
    ),

    // @deprecated by E21. Read from fees.fee_rules WHERE transaction_type='settlement'
    // via FeesModuleContract::calculateFee(). Only used by NullSettlementAdapter.
    'default_fee_rate' => '0',
];
