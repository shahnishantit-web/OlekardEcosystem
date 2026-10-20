<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Service;

use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ConcurrencyTestHelper;
use Tests\TestCase;

/**
 * CC-E15-001: Concurrent wallet debit prevention.
 *
 * Two simultaneous requests with the same Idempotency-Key from the same wallet
 * MUST result in exactly one ServiceTransaction row and exactly one ledger journal post.
 * (Constitution Principle 4; FR-E15-008)
 */
final class ServicePaymentConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_concurrent_duplicate_idempotency_key_produces_single_transaction(): void
    {
        // Build shared dependencies
        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);
        $audit   = $this->createMock(AuditServiceInterface::class);
        $events  = $this->createMock(EventPublisherInterface::class);

        $config->method('getOrDefault')->willReturn(1000000);
        $wallet->method('availableBalance')->willReturn(100000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref-bwp');
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-id-concurrency');
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('MOCK-CONC', 'completed'));
        $audit->method('record');
        $events->method('publish');

        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Concurrency Test Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'CONC_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 0,
            'required_fields' => [['name' => 'reference', 'label' => 'Reference']],
        ], 'admin-1', 'corr-setup');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $audit, $events
        );

        $idempotencyKey = 'concurrent-idem-'.uniqid();
        $customerId     = 'cust-concurrent';
        $walletId       = 'wallet-concurrent';

        $payload = [
            'service_id'         => $service->id,
            'wallet_id'          => $walletId,
            'customer_reference' => '1234567890',
            'account_name'       => 'Concurrent Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => $idempotencyKey,
        ];

        // Simulate 3 concurrent requests with the same idempotency key
        $results = ConcurrencyTestHelper::runConcurrently(3, function () use ($paymentService, $payload, $customerId) {
            try {
                return $paymentService->initiate($payload, $customerId, 'corr-concurrent');
            } catch (\Throwable $e) {
                return null;
            }
        });

        // Exactly ONE ServiceTransaction row must exist
        $count = ServiceTransactionModel::where('idempotency_key', $idempotencyKey)->count();
        $this->assertSame(1, $count, 'Exactly one ServiceTransaction must exist for the idempotency key');

        // All successful results must return the same transaction ID
        $ids = array_filter(array_map(
            fn ($r) => $r?->id,
            $results
        ));
        $this->assertCount(1, array_unique(array_values($ids)), 'All calls must return the same transaction ID');
    }

    public function test_concurrent_different_keys_each_create_own_transaction(): void
    {
        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);
        $audit   = $this->createMock(AuditServiceInterface::class);
        $events  = $this->createMock(EventPublisherInterface::class);

        $config->method('getOrDefault')->willReturn(1000000);
        $wallet->method('availableBalance')->willReturn(100000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-'.uniqid());
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('MOCK-'.uniqid(), 'completed'));
        $audit->method('record');
        $events->method('publish');

        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Concurrent Different Keys Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'CONC2_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 0,
            'required_fields' => [['name' => 'reference', 'label' => 'Reference']],
        ], 'admin-1', 'corr-setup');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $audit, $events
        );

        $customerId = 'cust-unique';

        // 3 concurrent requests each with a UNIQUE idempotency key — each should create its own tx
        $results = ConcurrencyTestHelper::runConcurrently(3, function () use ($paymentService, $service, $customerId) {
            $payload = [
                'service_id'         => $service->id,
                'wallet_id'          => 'wallet-unique',
                'customer_reference' => '1234567890',
                'account_name'       => 'Account',
                'amount'             => 1000,
                'currency_code'      => 'BWP',
                'idempotency_key'    => 'unique-'.uniqid(),
            ];
            return $paymentService->initiate($payload, $customerId, 'corr-'.uniqid());
        });

        $this->assertCount(3, $results);
        $ids = array_map(fn ($r) => $r->id, $results);
        $this->assertCount(3, array_unique($ids), 'Each unique idempotency key must produce a distinct transaction');
    }
}
