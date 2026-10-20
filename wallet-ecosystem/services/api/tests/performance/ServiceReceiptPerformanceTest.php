<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Application\Services\ServiceReceiptService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SC-E15-007: GET /v1/services/payments/{txId}/receipt must respond in < 1000ms.
 */
final class ServiceReceiptPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_receipt_retrieval_under_1000ms(): void
    {
        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');

        $catalog = new ServiceCatalogService($audit, $events);
        $service = $catalog->create([
            'name'            => 'Receipt Perf Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'RPERF_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter Number']],
        ], 'admin-1', 'corr-1');

        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);

        $config->method('getOrDefault')->willReturn(1000000);
        $wallet->method('availableBalance')->willReturn(100000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-rperf');
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-rperf');
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('RPERF-REF', 'completed'));

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $audit, $events
        );

        $tx = $paymentService->initiate([
            'service_id'         => $service->id,
            'wallet_id'          => 'wallet-rperf',
            'customer_reference' => '1234567890',
            'account_name'       => 'Receipt Perf Account',
            'amount'             => 10000,
            'currency_code'      => 'BWP',
            'idempotency_key'    => 'rperf-idem-'.uniqid(),
        ], 'customer-rperf', 'corr-rperf');

        $tx->status             = ServiceTransactionStatus::Completed;
        $tx->provider_reference = 'RPERF-COMPLETED';
        $tx->save();

        $receiptService = new ServiceReceiptService();
        $receiptService->createReceipt($tx->fresh());

        $timings = [];
        for ($i = 0; $i < 10; $i++) {
            $start     = microtime(true);
            $receiptService->getReceipt($tx->id, $tx->customer_id);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        sort($timings);
        $p95 = $timings[(int) ceil(count($timings) * 0.95) - 1];

        $this->assertLessThan(
            1000,
            $p95,
            "SC-E15-007: Receipt retrieval p95 ({$p95}ms) must be < 1000ms"
        );
    }
}
