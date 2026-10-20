<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Modules\Service\Application\Services\ServiceCatalogService;
use App\Modules\Service\Application\Services\ServiceFeeService;
use App\Modules\Service\Application\Services\ServicePaymentService;
use App\Modules\Service\Application\Services\ServiceValidationService;
use App\Modules\Service\Domain\Contracts\ServiceProviderAdapter;
use App\Modules\Service\Domain\DTO\PaymentSubmissionResult;
use App\Modules\Service\Domain\DTO\ReferenceValidationResult;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SC-E15-002: Reference validation must complete in < 5000ms.
 * SC-E15-003: Payment initiation must complete in < 3000ms.
 */
final class ServicePaymentPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reference_validation_completes_under_5000ms(): void
    {
        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);

        $config->method('getOrDefault')->willReturn(10);
        $adapter->method('validateReference')->willReturn(
            new ReferenceValidationResult(valid: true, accountName: 'Perf Account')
        );

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');

        $catalog = new ServiceCatalogService($audit, $events);
        $service = $catalog->create([
            'name'            => 'Validation Perf Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'VPERF_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 200,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter Number']],
        ], 'admin-1', 'corr-1');

        $validationService = new ServiceValidationService($adapter, $config);

        $timings = [];
        for ($i = 0; $i < 5; $i++) {
            $start     = microtime(true);
            $validationService->validateReference($service->id, '1234567890', 'corr-perf-'.$i);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        sort($timings);
        $p95 = $timings[(int) ceil(count($timings) * 0.95) - 1];

        $this->assertLessThan(
            5000,
            $p95,
            "SC-E15-002: Validation p95 ({$p95}ms) must be < 5000ms"
        );
    }

    public function test_payment_initiation_completes_under_3000ms(): void
    {
        $adapter = $this->createMock(ServiceProviderAdapter::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $wallet  = $this->createMock(WalletModuleContract::class);
        $config  = $this->createMock(ConfigurationServiceInterface::class);
        $audit   = $this->createMock(AuditServiceInterface::class);
        $events  = $this->createMock(EventPublisherInterface::class);

        $config->method('getOrDefault')->willReturn(1000000);
        $wallet->method('availableBalance')->willReturn(100000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-perf');
        $ledger->method('registerAccount');
        $ledger->method('postEntry')->willReturn('journal-perf');
        $adapter->method('submitPayment')->willReturn(new PaymentSubmissionResult('PERF-REF', 'completed'));
        $audit->method('record');
        $events->method('publish');

        $catalogAudit  = $this->createMock(AuditServiceInterface::class);
        $catalogEvents = $this->createMock(EventPublisherInterface::class);
        $catalogAudit->method('record');
        $catalogEvents->method('publish');

        $catalog = new ServiceCatalogService($catalogAudit, $catalogEvents);
        $service = $catalog->create([
            'name'            => 'Payment Perf Service',
            'category'        => 'UTILITIES',
            'provider_code'   => 'PPERF_'.uniqid(),
            'currency_code'   => 'BWP',
            'min_amount'      => 100,
            'max_amount'      => 1000000,
            'fee_type'        => 'flat',
            'fee_value'       => 0,
            'required_fields' => [['name' => 'meter', 'label' => 'Meter Number']],
        ], 'admin-1', 'corr-1');

        $paymentService = new ServicePaymentService(
            new ServiceFeeService(), $adapter, $ledger, $wallet, $config, $audit, $events
        );

        $timings = [];
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $paymentService->initiate([
                'service_id'         => $service->id,
                'wallet_id'          => 'wallet-perf',
                'customer_reference' => '1234567890',
                'account_name'       => 'Perf Account',
                'amount'             => 10000,
                'currency_code'      => 'BWP',
                'idempotency_key'    => 'perf-idem-'.uniqid(),
            ], 'customer-perf', 'corr-perf-'.$i);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        sort($timings);
        $p95 = $timings[(int) ceil(count($timings) * 0.95) - 1];

        $this->assertLessThan(
            3000,
            $p95,
            "SC-E15-003: Payment initiation p95 ({$p95}ms) must be < 3000ms"
        );
    }
}
