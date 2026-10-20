<?php

declare(strict_types=1);

namespace App\Modules\Qa\Domain\Enums;

enum QaCategory: string
{
    case UnitAuth             = 'unit_auth';
    case IntegrationAuth      = 'integration_auth';
    case ApiAuthorization     = 'api_authorization';
    case WalletBalance        = 'wallet_balance';
    case LedgerBalance        = 'ledger_balance';
    case Transfer             = 'transfer';
    case DuplicateTransaction = 'duplicate_transaction';
    case Callback             = 'callback';
    case PaymentFailure       = 'payment_failure';
    case Reconciliation       = 'reconciliation';
    case Kyc                  = 'kyc';
    case Agent                = 'agent';
    case Security             = 'security';
    case Performance          = 'performance';
    case Load                 = 'load';
    case DisasterRecovery     = 'disaster_recovery';
    case Uat                  = 'uat';
    case Regression           = 'regression';

    /** Maps URL path slugs (e.g. "unit-test-authentication") to enum cases. */
    public static function fromSlug(string $slug): self
    {
        return match ($slug) {
            'unit-test-authentication'    => self::UnitAuth,
            'integration-test-authentication' => self::IntegrationAuth,
            'api-authorization-testing'   => self::ApiAuthorization,
            'wallet-balance-testing'      => self::WalletBalance,
            'ledger-balance-testing'      => self::LedgerBalance,
            'transfer-testing'            => self::Transfer,
            'duplicate-transaction-testing' => self::DuplicateTransaction,
            'callback-testing'            => self::Callback,
            'payment-failure-testing'     => self::PaymentFailure,
            'reconciliation-testing'      => self::Reconciliation,
            'kyc-testing'                 => self::Kyc,
            'agent-testing'               => self::Agent,
            'security-testing'            => self::Security,
            'performance-testing'         => self::Performance,
            'load-testing'                => self::Load,
            'disaster-recovery-testing'   => self::DisasterRecovery,
            'uat'                         => self::Uat,
            'regression-testing'          => self::Regression,
            default => throw new \ValueError("Unknown QA category slug: {$slug}"),
        };
    }

    /** Returns the US-QA-NNN story number (e.g. "001") for event naming. */
    public function storyNumber(): string
    {
        return match ($this) {
            self::UnitAuth             => '001',
            self::IntegrationAuth      => '002',
            self::ApiAuthorization     => '003',
            self::WalletBalance        => '004',
            self::LedgerBalance        => '005',
            self::Transfer             => '006',
            self::DuplicateTransaction => '007',
            self::Callback             => '008',
            self::PaymentFailure       => '009',
            self::Reconciliation       => '010',
            self::Kyc                  => '011',
            self::Agent                => '012',
            self::Security             => '013',
            self::Performance          => '014',
            self::Load                 => '015',
            self::DisasterRecovery     => '016',
            self::Uat                  => '017',
            self::Regression           => '018',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UnitAuth             => 'Unit test authentication',
            self::IntegrationAuth      => 'Integration test authentication',
            self::ApiAuthorization     => 'API authorization testing',
            self::WalletBalance        => 'Wallet balance testing',
            self::LedgerBalance        => 'Ledger balance testing',
            self::Transfer             => 'Transfer testing',
            self::DuplicateTransaction => 'Duplicate transaction testing',
            self::Callback             => 'Callback testing',
            self::PaymentFailure       => 'Payment failure testing',
            self::Reconciliation       => 'Reconciliation testing',
            self::Kyc                  => 'KYC testing',
            self::Agent                => 'Agent testing',
            self::Security             => 'Security testing',
            self::Performance          => 'Performance testing',
            self::Load                 => 'Load testing',
            self::DisasterRecovery     => 'Disaster recovery testing',
            self::Uat                  => 'UAT',
            self::Regression           => 'Regression testing',
        };
    }
}
