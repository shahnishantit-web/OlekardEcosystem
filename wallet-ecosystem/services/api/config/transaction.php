<?php

declare(strict_types=1);

return [
    'reversal' => [
        'require_maker_checker' => env('TRANSACTION_REVERSAL_REQUIRE_MAKER_CHECKER', true),
        'reversible_types'      => ['withdrawal', 'funding'],
    ],

    'receipt' => [
        'share_channels' => ['email', 'sms'],
    ],
];
