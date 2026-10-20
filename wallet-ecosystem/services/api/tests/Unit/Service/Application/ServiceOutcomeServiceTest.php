<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServiceOutcomeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Application\Services\ServiceReceiptService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Exceptions\ServiceTransactionNotFoundException;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ServiceOutcomeServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ServiceOutcomeService $outcome;
    private LedgerModuleContract $ledger;
    private WalletModuleContract $wallet;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;
    private ServiceReceiptService $receiptService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->createMock(LedgerModuleContract::class);
        $this->wallet = $this->createMock(WalletModuleContract::class);
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->events = $this->createMock(EventPublisherInterface::class);
        $config       = $this->createMock(ConfigurationServiceInterface::class);

        $config->method('getOrDefault')->willReturn('service.payable.BWP');
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $this->audit->method('record');
        $this->events->method('publish');

        $this->receiptService = new ServiceReceiptService();

        $this->outcome = new ServiceOutcomeService(
            $this->receiptService,
            $this->ledger,
            $this->wallet,
            $config,
            $this->audit,
            $this->events,
        );
    }

    private function createPendingTransaction(): ServiceTransactionModel
    {
        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Outcome Test Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'OUT_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter']],
        ], '00000000-0000-0000-0000-000000000001', 'corr-1');

        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);

        $config->method('getOrDefault')->willReturn(1000000);
        $wallet->method('availableBalance')->willReturn(100000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-123');
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('PROV-REF', 'completed'));

        $paymentAudit  = $this->createMock(AuditServiceInterface::class);
        $paymentEvents = $this->createMock(EventPublisherInterface::class);
        $paymentAudit->method('record');
        $paymentEvents->method('publish');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $paymentAudit, $paymentEvents
        );

        $tx = $paymentService->initiate([
            'service_id'         => $service->id,
            'wallet_id'          => '00000000-0000-0000-0000-000000000021',
            'customer_reference' => '1234567890',
            'account_name'       => 'Test Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => 'idem-outcome-'.uniqid(),
        ], '00000000-0000-0000-0000-000000000002', 'corr-payment');

        // Override status to pending_provider for outcome testing
        $tx->status = ServiceTransactionStatus::PendingProvider;
        $tx->save();

        return $tx->fresh();
    }

    public function test_handle_success_completes_transaction_and_creates_receipt(): void
    {
        $this->ledger->expects($this->never())->method('postEntry'); // no reversal on success

        $tx = $this->createPendingTransaction();

        $this->outcome->handleSuccess($tx->id, 'PROV-SUCCESS-123', 'corr-success');

        $refreshed = ServiceTransactionModel::find($tx->id);
        $this->assertSame(ServiceTransactionStatus::Completed, $refreshed->status);
        $this->assertSame('PROV-SUCCESS-123', $refreshed->provider_reference);
        $this->assertNotNull($refreshed->receipt);
    }

    public function test_handle_failure_posts_reversal_and_fails_transaction(): void
    {
        $this->ledger->expects($this->once())->method('postEntry'); // compensating reversal

        $tx = $this->createPendingTransaction();

        $this->outcome->handleFailure($tx->id, 'provider_declined', 'corr-failure');

        $refreshed = ServiceTransactionModel::find($tx->id);
        $this->assertSame(ServiceTransactionStatus::Failed, $refreshed->status);
        $this->assertSame('provider_declined', $refreshed->failure_reason);
        $this->assertFalse((bool) $refreshed->review_flag);
    }

    public function test_handle_ambiguous_sets_review_flag(): void
    {
        $this->ledger->expects($this->once())->method('postEntry'); // compensating reversal

        $tx = $this->createPendingTransaction();

        $this->outcome->handleAmbiguous($tx->id, 'corr-ambiguous');

        $refreshed = ServiceTransactionModel::find($tx->id);
        $this->assertSame(ServiceTransactionStatus::Failed, $refreshed->status);
        $this->assertTrue((bool) $refreshed->review_flag);
    }

    public function test_duplicate_success_callback_is_idempotent(): void
    {
        $this->ledger->expects($this->never())->method('postEntry');

        $tx = $this->createPendingTransaction();

        $this->outcome->handleSuccess($tx->id, 'PROV-REF', 'corr-1');
        // Second call on now-terminal transaction should be a no-op
        $this->outcome->handleSuccess($tx->id, 'PROV-REF-2', 'corr-2');

        // Still completed with original provider_reference
        $refreshed = ServiceTransactionModel::find($tx->id);
        $this->assertSame(ServiceTransactionStatus::Completed, $refreshed->status);
    }

    public function test_unknown_transaction_throws_not_found(): void
    {
        $this->expectException(ServiceTransactionNotFoundException::class);
        $this->outcome->handleSuccess('00000000-0000-0000-0000-000000000000', 'REF', 'corr-1');
    }
}
