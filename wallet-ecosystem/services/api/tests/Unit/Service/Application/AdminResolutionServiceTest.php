<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Application;

use App\Modules\Service\Application\Services\AdminResolutionService;
use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServiceOutcomeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Application\Services\ServiceReceiptService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Service\Domain\Exceptions\TransactionNotModifiableException;
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class AdminResolutionServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AdminResolutionService $resolution;
    private LedgerModuleContract $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->createMock(LedgerModuleContract::class);
        $wallet       = $this->createMock(WalletModuleContract::class);
        $config       = $this->createMock(ConfigurationServiceInterface::class);
        $audit        = $this->createMock(AuditServiceInterface::class);
        $events       = $this->createMock(EventPublisherInterface::class);

        $config->method('getOrDefault')->willReturn('service.payable.BWP');
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');
        $audit->method('record');
        $events->method('publish');

        $receiptService = new ServiceReceiptService();

        $outcome = new ServiceOutcomeService(
            $receiptService, $this->ledger, $wallet, $config, $audit, $events
        );

        $this->resolution = new AdminResolutionService($outcome, $audit);
    }

    private function createTimeoutTransaction(): ServiceTransactionModel
    {
        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Admin Resolution Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'RES_'.uniqid(),
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
        $ledger->method('postEntry')->willReturn('journal-xyz');
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
            'wallet_id'          => '00000000-0000-0000-0000-000000000020',
            'customer_reference' => '1234567890',
            'account_name'       => 'Test Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => 'idem-res-'.uniqid(),
        ], '00000000-0000-0000-0000-000000000010', 'corr-payment');

        $tx->status = ServiceTransactionStatus::TimeoutPendingReview;
        $tx->save();

        return $tx->fresh();
    }

    public function test_force_success_on_timeout_pending_review(): void
    {
        $this->ledger->expects($this->never())->method('postEntry'); // no reversal on success

        $tx = $this->createTimeoutTransaction();
        $resolved = $this->resolution->resolve(
            txId:          $tx->id,
            outcomeChoice: 'success',
            rationale:     'Confirmed with provider',
            actorId:       '00000000-0000-0000-0000-000000000001',
            correlationId: 'corr-resolve',
            providerRef:   'CONFIRMED-REF',
        );

        $this->assertSame(ServiceTransactionStatus::Completed, $resolved->status);
    }

    public function test_force_failure_on_timeout_pending_review(): void
    {
        $this->ledger->expects($this->once())->method('postEntry'); // compensating reversal

        $tx = $this->createTimeoutTransaction();
        $resolved = $this->resolution->resolve(
            txId:          $tx->id,
            outcomeChoice: 'failure',
            rationale:     'Provider confirmed never processed',
            actorId:       '00000000-0000-0000-0000-000000000001',
            correlationId: 'corr-resolve',
        );

        $this->assertSame(ServiceTransactionStatus::Failed, $resolved->status);
    }

    public function test_resolve_on_completed_status_throws_not_modifiable(): void
    {
        $tx = $this->createTimeoutTransaction();
        $tx->status = ServiceTransactionStatus::Completed;
        $tx->save();

        $this->expectException(TransactionNotModifiableException::class);
        $this->resolution->resolve(
            txId:          $tx->id,
            outcomeChoice: 'failure',
            rationale:     'Should not be allowed',
            actorId:       '00000000-0000-0000-0000-000000000001',
            correlationId: 'corr-resolve',
        );
    }

    public function test_resolve_on_failed_status_throws_not_modifiable(): void
    {
        $tx = $this->createTimeoutTransaction();
        $tx->status = ServiceTransactionStatus::Failed;
        $tx->save();

        $this->expectException(TransactionNotModifiableException::class);
        $this->resolution->resolve(
            txId:          $tx->id,
            outcomeChoice: 'success',
            rationale:     'Should not be allowed',
            actorId:       '00000000-0000-0000-0000-000000000001',
            correlationId: 'corr-resolve',
        );
    }
}
