<?php

return [
    App\Providers\AppServiceProvider::class,

    // Platform Foundation — cross-cutting infrastructure (fully implemented)
    App\Modules\Platform\Providers\PlatformServiceProvider::class,

    // E02 — User & Profile Management
    App\Modules\Profile\Infrastructure\Providers\ProfileServiceProvider::class,

    // Stub modules — schemas created; business logic deferred to E01–E11
    App\Modules\Identity\Providers\IdentityServiceProvider::class,
    App\Modules\Wallet\Providers\WalletServiceProvider::class,
    App\Modules\Ledger\Providers\LedgerServiceProvider::class,
    App\Modules\Kyc\Providers\KycServiceProvider::class,
    App\Modules\Transaction\Providers\TransactionServiceProvider::class,
    App\Modules\Risk\Providers\RiskServiceProvider::class,
    App\Modules\Agent\Providers\AgentServiceProvider::class,
    App\Modules\Notification\Providers\NotificationServiceProvider::class,
];
