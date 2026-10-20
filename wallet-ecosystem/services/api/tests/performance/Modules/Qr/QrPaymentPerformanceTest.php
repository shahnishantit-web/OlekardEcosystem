<?php

declare(strict_types=1);

namespace Tests\Performance\Modules\Qr;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Qr\Application\Services\QrCodeService;
use App\Modules\Qr\Application\Services\QrPayloadSigner;
use App\Modules\Qr\Application\Services\QrPaymentService;
use App\Modules\Qr\Application\Services\QrReceiptService;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Qr\Domain\DTO\ExecuteQrPaymentCommand;
use App\Modules\Qr\Domain\DTO\GenerateQrCodeInput;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Infrastructure\Models\QrPaymentReceiptModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SC-E16-001: QR generation < 1000ms
 * SC-E16-002: QR resolution < 2000ms
 * SC-E16-003: QR payment confirmation < 3000ms
 */
final class QrPaymentPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    private QrCodeService    $codeService;
    private QrPaymentService $paymentService;
    private QrPayloadSigner  $signer;
    private ConfigurationServiceInterface $config;
    private MerchantReadServiceInterface  $merchantRead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('get')->willReturnMap([
            ['qr.signing_key',           'perf-test-key'],
            ['qr.dynamic_expiry_seconds', '7200'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',       'flat'],
            ['qr.payment.fee_value',      '0'],
            ['qr.payment.min_amount',     '1'],
            ['qr.payment.max_amount',     '100000000'],
            ['auth.step_up_threshold',    '100000000'],
            ['ledger.fee_wallet.BWP',     'fee-ref'],
        ]);

        $this->merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $this->merchantRead->method('isActive')->willReturn(true);
        $this->merchantRead->method('getDisplayName')->willReturn('Perf Merchant');

        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->method('availableBalance')->willReturn(10_000_000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-perf');

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('journal-perf');

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);

        $receiptService = $this->createMock(QrReceiptService::class);
        $receiptService->method('createForTransaction')->willReturnCallback(function ($tx) {
            $receipt                          = new QrPaymentReceiptModel();
            $receipt->id                      = 'r-' . $tx->id;
            $receipt->receipt_ref             = 'QRPMT-' . uniqid();
            $receipt->qr_payment_transaction_id = $tx->id;
            $receipt->merchant_display_name   = 'Perf Merchant';
            $receipt->amount                  = $tx->amount;
            $receipt->fee                     = 0;
            $receipt->total_debited           = $tx->amount;
            $receipt->currency                = $tx->currency;
            $receipt->issued_at               = now();
            return $receipt;
        });

        $this->signer      = new QrPayloadSigner($this->config);
        $this->codeService = new QrCodeService($this->signer, $this->merchantRead, $this->config, $audit);

        $this->paymentService = new QrPaymentService(
            $this->signer, $this->merchantRead, $wallet, $ledger,
            $this->config, $audit, $events, $receiptService
        );
    }

    /**
     * SC-E16-001: POST /v1/qr-codes median < 1000ms.
     */
    public function test_sc_e16_001_qr_generation_under_1000ms(): void
    {
        $timings = [];
        $input   = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);

        for ($i = 0; $i < 5; $i++) {
            $start     = microtime(true);
            $this->codeService->generate($input, 'merchant-wallet-perf', 'corr-gen-' . $i);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        sort($timings);
        $median = $timings[(int) floor(count($timings) / 2)];

        $this->assertLessThan(
            1000,
            $median,
            "SC-E16-001: QR generation median ({$median}ms) must be < 1000ms"
        );
    }

    /**
     * SC-E16-002: POST /v1/qr-payments/resolve median < 2000ms.
     */
    public function test_sc_e16_002_qr_resolve_under_2000ms(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, 'merchant-wallet-perf', 'corr-x');

        $timings = [];

        for ($i = 0; $i < 5; $i++) {
            $start     = microtime(true);
            $this->paymentService->resolve($qrCode->payload, 'customer-perf', 'corr-res-' . $i);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        sort($timings);
        $median = $timings[(int) floor(count($timings) / 2)];

        $this->assertLessThan(
            2000,
            $median,
            "SC-E16-002: QR resolution median ({$median}ms) must be < 2000ms"
        );
    }

    /**
     * SC-E16-003: POST /v1/qr-payments (confirm) median < 3000ms.
     */
    public function test_sc_e16_003_qr_confirm_under_3000ms(): void
    {
        $input   = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $timings = [];

        for ($i = 0; $i < 5; $i++) {
            $qrCode = $this->codeService->generate($input, 'merchant-wallet-perf', 'corr-x-' . $i);

            $cmd = new ExecuteQrPaymentCommand(
                qrCodeId:         $qrCode->id,
                customerWalletId: 'customer-wallet-perf',
                amount:           '20.00',
                currency:         'BWP',
                idempotencyKey:   'perf-idem-' . uniqid(),
                correlationId:    'corr-conf-' . $i,
                stepUpToken:      null,
            );

            $start     = microtime(true);
            $this->paymentService->confirm($cmd);
            $timings[] = (microtime(true) - $start) * 1000;
        }

        sort($timings);
        $median = $timings[(int) floor(count($timings) / 2)];

        $this->assertLessThan(
            3000,
            $median,
            "SC-E16-003: QR confirm median ({$median}ms) must be < 3000ms"
        );
    }
}
