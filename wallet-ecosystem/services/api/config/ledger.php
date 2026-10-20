<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Ledger System Account References (TBD-E07-001)
    |--------------------------------------------------------------------------
    | These config keys are stubs. All values are null until populated before
    | the E08 epic. They are resolved at runtime via platform.configuration_values.
    | Do NOT hard-code UUIDs here — populate via DB seeder when known.
    */
    'system' => [
        'float_account_ref'      => null,
        'fee_account_ref'        => null,
        'revenue_account_ref'    => null,
        'commission_account_ref' => null,
        'suspense_account_ref'   => null,
        'is_tbd'                 => true,
    ],
];
