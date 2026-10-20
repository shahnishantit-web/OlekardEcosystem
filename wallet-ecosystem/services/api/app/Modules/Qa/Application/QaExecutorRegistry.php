<?php

declare(strict_types=1);

namespace App\Modules\Qa\Application;

use App\Modules\Qa\Application\Executors\AgentExecutor;
use App\Modules\Qa\Application\Executors\ApiAuthorizationExecutor;
use App\Modules\Qa\Application\Executors\AuthIntegrationTestExecutor;
use App\Modules\Qa\Application\Executors\AuthUnitTestExecutor;
use App\Modules\Qa\Application\Executors\CallbackExecutor;
use App\Modules\Qa\Application\Executors\DisasterRecoveryExecutor;
use App\Modules\Qa\Application\Executors\DuplicateTransactionExecutor;
use App\Modules\Qa\Application\Executors\KycExecutor;
use App\Modules\Qa\Application\Executors\LedgerBalanceExecutor;
use App\Modules\Qa\Application\Executors\LoadExecutor;
use App\Modules\Qa\Application\Executors\PaymentFailureExecutor;
use App\Modules\Qa\Application\Executors\PerformanceExecutor;
use App\Modules\Qa\Application\Executors\ReconciliationExecutor;
use App\Modules\Qa\Application\Executors\RegressionExecutor;
use App\Modules\Qa\Application\Executors\SecurityExecutor;
use App\Modules\Qa\Application\Executors\TransferExecutor;
use App\Modules\Qa\Application\Executors\UatExecutor;
use App\Modules\Qa\Application\Executors\WalletBalanceExecutor;
use App\Modules\Qa\Application\Executors\Contracts\QaExecutorInterface;
use App\Modules\Qa\Domain\Enums\QaCategory;
use Illuminate\Contracts\Container\Container;

final class QaExecutorRegistry
{
    /** @var array<string, class-string<QaExecutorInterface>> */
    private array $map = [
        QaCategory::UnitAuth->value             => AuthUnitTestExecutor::class,
        QaCategory::IntegrationAuth->value      => AuthIntegrationTestExecutor::class,
        QaCategory::ApiAuthorization->value     => ApiAuthorizationExecutor::class,
        QaCategory::WalletBalance->value        => WalletBalanceExecutor::class,
        QaCategory::LedgerBalance->value        => LedgerBalanceExecutor::class,
        QaCategory::Transfer->value             => TransferExecutor::class,
        QaCategory::DuplicateTransaction->value => DuplicateTransactionExecutor::class,
        QaCategory::Callback->value             => CallbackExecutor::class,
        QaCategory::PaymentFailure->value       => PaymentFailureExecutor::class,
        QaCategory::Reconciliation->value       => ReconciliationExecutor::class,
        QaCategory::Kyc->value                  => KycExecutor::class,
        QaCategory::Agent->value                => AgentExecutor::class,
        QaCategory::Security->value             => SecurityExecutor::class,
        QaCategory::Performance->value          => PerformanceExecutor::class,
        QaCategory::Load->value                 => LoadExecutor::class,
        QaCategory::DisasterRecovery->value     => DisasterRecoveryExecutor::class,
        QaCategory::Uat->value                  => UatExecutor::class,
        QaCategory::Regression->value           => RegressionExecutor::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function resolve(QaCategory $category): QaExecutorInterface
    {
        $class = $this->map[$category->value]
            ?? throw new \DomainException("No executor registered for QA category: {$category->value}");

        return $this->container->make($class);
    }
}
