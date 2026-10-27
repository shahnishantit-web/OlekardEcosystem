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

    // E08 — Wallet Funding
    App\Modules\Funding\Providers\FundingServiceProvider::class,

    // E09 — Wallet-to-Wallet Transfer
    App\Modules\Transfer\Providers\TransferServiceProvider::class,

    // E10 — Withdrawal / Cash-Out
    App\Modules\Withdrawal\Providers\WithdrawalServiceProvider::class,

    // E13 — Agent Float Management
    App\Modules\AgentFloat\Providers\AgentFloatServiceProvider::class,

    // E14 — Agent Commission
    App\Modules\Commission\Providers\CommissionServiceProvider::class,

    // E15 — Service & Bill Payments
    App\Modules\Service\Providers\ServiceServiceProvider::class,

    // E16 — QR & Merchant Payments
    App\Modules\Qr\Providers\QrServiceProvider::class,

    // E19 — Admin Portal
    App\Modules\Admin\Infrastructure\Providers\AdminServiceProvider::class,

    // E20 — Reconciliation & Settlement
    App\Modules\Reconciliation\Infrastructure\Providers\ReconciliationServiceProvider::class,

    // E21 — Fees & Pricing
    App\Modules\Fees\Providers\FeesServiceProvider::class,

    // E22 — Support & Dispute Management
    App\Modules\Support\Providers\SupportServiceProvider::class,

    // E23 — Audit & Compliance
    App\Modules\Audit\AuditServiceProvider::class,

    // E24 — Reporting & Analytics
    App\Modules\Reporting\ReportingServiceProvider::class,

    // E25 — API & Integration Platform
    App\Modules\Integration\Providers\IntegrationServiceProvider::class,

    // E26 — Security Hardening
    App\Modules\Security\Providers\SecurityServiceProvider::class,

    // E29 — Multi-Currency Wallet
    App\Modules\MultiCurrency\Providers\MultiCurrencyServiceProvider::class,

    // E30 — DevOps & Operations
    App\Modules\Ops\Providers\OpsServiceProvider::class,

    // E33 — Admin Application
    App\Modules\AdminApp\Infrastructure\Providers\AdminAppServiceProvider::class,

    // E34 — QA & Validation
    App\Modules\Qa\QaServiceProvider::class,

    // E48 — Shared Payment Integration & Orchestration
    App\Modules\Payment\Providers\PaymentServiceProvider::class,

    // P24 Funeral Assistance reuses shared Payments and community storage.
    App\Modules\CommunityFuneral\Providers\CommunityFuneralServiceProvider::class,

    // P25 draft foundation; organiser authority is denied until approved.
    App\Modules\Events\Providers\EventsServiceProvider::class,

];
