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
use App\Modules\Service\Infrastructure\Models\ServiceTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class ServiceReceiptServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ServiceReceiptService $receiptService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptService = new ServiceReceiptService();
    }

    private function createCompletedTransaction(): ServiceTransactionModel
    {
        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Receipt Test Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'RCT_'.uniqid(),
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
        $ledger->method('postEntry')->willReturn('journal-receipt');
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('PROV-RECEIPT', 'completed'));

        $paymentAudit  = $this->createMock(AuditServiceInterface::class);
        $paymentEvents = $this->createMock(EventPublisherInterface::class);
        $paymentAudit->method('record');
        $paymentEvents->method('publish');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $paymentAudit, $paymentEvents
        );

        $tx = $paymentService->initiate([
            'service_id'         => $service->id,
            'wallet_id'          => '00000000-0000-0000-0000-000000000022',
            'customer_reference' => '1234567890',
            'account_name'       => 'Receipt Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => 'idem-receipt-'.uniqid(),
        ], '00000000-0000-0000-0000-000000000011', 'corr-payment');

        // Manually complete the transaction for receipt testing
        $tx->status             = ServiceTransactionStatus::Completed;
        $tx->provider_reference = 'PROV-COMPLETED';
        $tx->save();

        return $tx->fresh();
    }

    public function test_create_receipt_generates_unique_receipt_reference(): void
    {
        $tx = $this->createCompletedTransaction();

        $receipt = $this->receiptService->createReceipt($tx);

        $this->assertNotNull($receipt->receipt_reference);
        $this->assertSame($tx->id, $receipt->transaction_id);
        $this->assertSame(10000, $receipt->amount);
        $this->assertSame(200, $receipt->fee_amount);
    }

    public function test_create_receipt_is_idempotent(): void
    {
        $tx = $this->createCompletedTransaction();

        $receipt1 = $this->receiptService->createReceipt($tx);
        $receipt2 = $this->receiptService->createReceipt($tx);

        $this->assertSame($receipt1->id, $receipt2->id);
        $this->assertSame($receipt1->receipt_reference, $receipt2->receipt_reference);
    }

    public function test_get_receipt_succeeds_for_completed_transaction(): void
    {
        $tx = $this->createCompletedTransaction();
        $this->receiptService->createReceipt($tx);

        $receipt = $this->receiptService->getReceipt($tx->id, $tx->customer_id);

        $this->assertNotNull($receipt);
        $this->assertSame($tx->id, $receipt->transaction_id);
    }

    public function test_get_receipt_throws_for_pending_transaction(): void
    {
        $tx = $this->createCompletedTransaction();
        $tx->status = ServiceTransactionStatus::PendingProvider;
        $tx->save();

        try {
            $this->receiptService->getReceipt($tx->id, $tx->customer_id);
            $this->fail('Expected HttpException was not thrown');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_get_receipt_throws_for_different_customer(): void
    {
        $tx = $this->createCompletedTransaction();
        $this->receiptService->createReceipt($tx);

        try {
            $this->receiptService->getReceipt($tx->id, 'different-customer-id');
            $this->fail('Expected HttpException was not thrown');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
