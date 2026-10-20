<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Qr;

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
use App\Modules\Qr\Domain\Enums\QrCodeStatus;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use App\Modules\Qr\Domain\Exceptions\QrReservedException;
use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentReceiptModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class QrPaymentConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private QrPaymentService $paymentService;
    private QrCodeService    $codeService;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['qr.signing_key',           'test-concurrency-key'],
            ['qr.dynamic_expiry_seconds', '7200'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',       'flat'],
            ['qr.payment.fee_value',      '0'],
            ['qr.payment.min_amount',     '1'],
            ['qr.payment.max_amount',     '100000000'],
            ['auth.step_up_threshold',    '100000000'],
            ['ledger.fee_wallet.BWP',     'fee-ref'],
        ]);

        $merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $merchantRead->method('isActive')->willReturn(true);
        $merchantRead->method('getDisplayName')->willReturn('Concurrent Merchant');

        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->method('availableBalance')->willReturn(1_000_000_00);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('ledger-ref');

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('journal-concurrency');

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);

        $receiptService = $this->createMock(QrReceiptService::class);
        $receiptService->method('createForTransaction')->willReturnCallback(function ($tx) {
            $receipt                          = new QrPaymentReceiptModel();
            $receipt->id                      = 'receipt-' . $tx->id;
            $receipt->receipt_ref             = 'QRPMT-' . uniqid();
            $receipt->qr_payment_transaction_id = $tx->id;
            $receipt->merchant_display_name   = 'Concurrent Merchant';
            $receipt->amount                  = $tx->amount;
            $receipt->fee                     = $tx->fee;
            $receipt->total_debited           = $tx->amount + $tx->fee;
            $receipt->currency                = $tx->currency;
            $receipt->issued_at               = now();
            return $receipt;
        });

        $signer            = new QrPayloadSigner($config);
        $this->codeService = new QrCodeService($signer, $merchantRead, $config, $audit);

        $this->paymentService = new QrPaymentService(
            $signer, $merchantRead, $wallet, $ledger, $config, $audit, $events, $receiptService
        );
    }

    /**
     * Scenario A: Two sequential confirms on same dynamic QR.
     * The second confirm must receive QrReservedException after the first completes.
     */
    public function test_two_confirms_on_same_dynamic_qr_only_one_succeeds(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Dynamic, null, '20.00', 'BWP', null, 7200);
        $qrCode = $this->codeService->generate($input, 'merchant-wallet-001', 'corr-x');

        $cmd1 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: 'customer-wallet-A',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   'idem-concurrent-a-' . uniqid(),
            correlationId:    'corr-a',
            stepUpToken:      null,
        );

        $result1 = $this->paymentService->confirm($cmd1);
        $this->assertSame(QrPaymentStatus::Completed, $result1->status);

        $cmd2 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: 'customer-wallet-B',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   'idem-concurrent-b-' . uniqid(),
            correlationId:    'corr-b',
            stepUpToken:      null,
        );

        try {
            $this->paymentService->confirm($cmd2);
            $this->fail('Expected QrAlreadyUsedException to be thrown');
        } catch (\App\Modules\Qr\Domain\Exceptions\QrAlreadyUsedException $e) {
            // Expected: dynamic QR is now 'used'
        }

        $qrCode->refresh();
        $this->assertSame(QrCodeStatus::Used, $qrCode->status);

        // Only one completed transaction exists for this QR
        $completedCount = QrPaymentTransactionModel::where('qr_code_id', $qrCode->id)
            ->where('status', QrPaymentStatus::Completed->value)
            ->count();
        $this->assertSame(1, $completedCount);
    }

    /**
     * Scenario B: Same idempotency key submitted twice.
     * Only one row should exist; both responses should be identical.
     */
    public function test_identical_idempotency_key_produces_single_transaction(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, 'merchant-wallet-001', 'corr-x');

        $idempotencyKey = 'idem-idempotent-' . uniqid();

        $cmd1 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: 'customer-wallet-A',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   $idempotencyKey,
            correlationId:    'corr-idem-1',
            stepUpToken:      null,
        );

        $result1 = $this->paymentService->confirm($cmd1);

        // Second call with identical key (different QR, same key)
        $qrCode2 = $this->codeService->generate($input, 'merchant-wallet-001', 'corr-x2');
        $cmd2    = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode2->id,
            customerWalletId: 'customer-wallet-A',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   $idempotencyKey,
            correlationId:    'corr-idem-2',
            stepUpToken:      null,
        );

        $result2 = $this->paymentService->confirm($cmd2);

        $this->assertSame($result1->id, $result2->id);

        $rowCount = QrPaymentTransactionModel::where('idempotency_key', $idempotencyKey)->count();
        $this->assertSame(1, $rowCount);
    }
}
