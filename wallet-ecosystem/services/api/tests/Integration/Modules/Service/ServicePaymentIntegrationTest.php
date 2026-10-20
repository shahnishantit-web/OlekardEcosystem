<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Service;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SC7 / QS-E15-001: Verifies that on INSUFFICIENT_BALANCE, a ServiceTransaction row
 * with status = 'initiated' is persisted, satisfying the quickstart SQL check.
 */
final class ServicePaymentIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_insufficient_balance_preserves_initiated_record_in_database(): void
    {
        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');

        $catalog = new ServiceCatalogService($audit, $events);
        $service = $catalog->create([
            'name'            => 'Integration Test Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'INT_TEST_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-setup');

        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);

        $config->method('getOrDefault')->willReturn(1000000);
        // Very low balance — insufficient for 10000 + 200 fee = 10200
        $wallet->method('availableBalance')->willReturn(100);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $ledger->method('registerAccount');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $audit, $events
        );

        $idempotencyKey = 'sc7-integration-'.uniqid();
        $customerId     = '00000000-0000-0000-0000-000000000013';

        $exceptionThrown = false;
        try {
            $paymentService->initiate([
                'service_id'         => $service->id,
                'wallet_id'          => '00000000-0000-0000-0000-000000000024',
                'customer_reference' => '1234567890',
                'account_name'       => 'SC7 Account',
                'amount'             => 10000,
                'currency_code'      => 'BWP',
                'idempotency_key'    => $idempotencyKey,
            ], $customerId, 'corr-sc7');
        } catch (InsufficientBalanceException) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown, 'InsufficientBalanceException must be thrown');

        // SC7 SQL check equivalent:
        // SELECT id, status FROM service.service_transactions WHERE idempotency_key = '$idempotencyKey';
        // Expected: 1 row; status = 'initiated'
        $record = ServiceTransactionModel::where('idempotency_key', $idempotencyKey)->first();

        $this->assertNotNull($record, 'A service_transaction record MUST exist with the idempotency key');
        $this->assertSame(
            ServiceTransactionStatus::Initiated,
            $record->status,
            "Transaction status must be 'initiated' after INSUFFICIENT_BALANCE (quickstart SC7 check)"
        );
    }
}
