<?php

declare(strict_types=1);

namespace Tests\Unit\Qr\Application;

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
use App\Modules\Qr\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentReceiptModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class QrPaymentServiceConfirmTest extends TestCase
{
    use DatabaseTransactions;

    private QrPaymentService $paymentService;
    private QrCodeService    $codeService;
    private WalletModuleContract $wallet;
    private LedgerModuleContract $ledger;
    private MerchantReadServiceInterface $merchantRead;
    private ConfigurationServiceInterface $config;
    private QrReceiptService $receiptService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('get')->willReturnMap([
            ['qr.signing_key',           'test-confirm-key'],
            ['qr.dynamic_expiry_seconds', '7200'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',       'flat'],
            ['qr.payment.fee_value',      '100'],
            ['qr.payment.min_amount',     '100'],
            ['qr.payment.max_amount',     '100000000'],
            ['auth.step_up_threshold',    '1000000'],
            ['ledger.fee_wallet.BWP',     'fee-ledger-ref'],
        ]);

        $this->merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $this->merchantRead->method('isActive')->willReturn(true);
        $this->merchantRead->method('getDisplayName')->willReturn('Test Merchant');

        $this->wallet  = $this->createMock(WalletModuleContract::class);
        $this->ledger  = $this->createMock(LedgerModuleContract::class);
        $audit         = $this->createMock(AuditServiceInterface::class);
        $events        = $this->createMock(EventPublisherInterface::class);

        $this->receiptService = $this->createMock(QrReceiptService::class);
        $this->receiptService->method('createForTransaction')->willReturnCallback(function ($tx) {
            $receipt                          = new QrPaymentReceiptModel();
            $receipt->id                      = 'receipt-' . $tx->id;
            $receipt->receipt_ref             = 'QRPMT-20260930-ABCDE';
            $receipt->qr_payment_transaction_id = $tx->id;
            $receipt->merchant_display_name   = 'Test Merchant';
            $receipt->amount                  = $tx->amount;
            $receipt->fee                     = $tx->fee;
            $receipt->total_debited           = $tx->amount + $tx->fee;
            $receipt->currency                = $tx->currency;
            $receipt->issued_at               = now();
            return $receipt;
        });

        $signer            = new QrPayloadSigner($this->config);
        $this->codeService = new QrCodeService($signer, $this->merchantRead, $this->config, $audit);

        $this->paymentService = new QrPaymentService(
            $signer, $this->merchantRead, $this->wallet, $this->ledger,
            $this->config, $audit, $events, $this->receiptService
        );
    }

    private function makeStaticQr(): QrCodeModel
    {
        $input = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        return $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-x');
    }

    private function makeDynamicQr(string $amount = '20.00'): QrCodeModel
    {
        $input = new GenerateQrCodeInput(QrCodeType::Dynamic, null, $amount, 'BWP', null, 7200);
        return $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-x');
    }

    private function makeCmd(QrCodeModel $qr, array $overrides = []): ExecuteQrPaymentCommand
    {
        return new ExecuteQrPaymentCommand(
            qrCodeId:         $qr->id,
            customerWalletId: $overrides['customerWalletId'] ?? '00000000-0000-0000-0000-000000000015',
            amount:           $overrides['amount'] ?? '20.00',
            currency:         'BWP',
            idempotencyKey:   $overrides['idempotencyKey'] ?? 'idem-' . uniqid(),
            correlationId:    'corr-' . uniqid(),
            stepUpToken:      $overrides['stepUpToken'] ?? null,
        );
    }

    public function test_happy_path_completes_with_triple_leg_journal(): void
    {
        $qr = $this->makeStaticQr();

        $this->wallet->method('availableBalance')->willReturn(1_000_000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('ledger-ref');
        $this->ledger->expects($this->once())->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000101');

        $result = $this->paymentService->confirm($this->makeCmd($qr));

        $this->assertSame(QrPaymentStatus::Completed, $result->status);
        $this->assertSame(2000, $result->amount);
        $this->assertSame(100, $result->fee);
        $this->assertSame(2100, $result->totalDebited);
    }

    public function test_insufficient_balance_fails_tx_and_returns_qr_to_active(): void
    {
        $qr = $this->makeStaticQr();

        $this->wallet->method('availableBalance')->willReturn(100);
        $this->ledger->expects($this->never())->method('postEntry');

        $this->expectException(InsufficientBalanceException::class);
        $this->paymentService->confirm($this->makeCmd($qr));

        $qr->refresh();
        $this->assertSame(QrCodeStatus::Active, $qr->status);
    }

    public function test_idempotency_replay_returns_same_result_without_second_journal(): void
    {
        $qr = $this->makeStaticQr();

        $this->wallet->method('availableBalance')->willReturn(1_000_000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('ledger-ref');
        $this->ledger->expects($this->once())->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000102');

        $idempotencyKey = 'idem-replay-' . uniqid();
        $cmd1 = $this->makeCmd($qr, ['idempotencyKey' => $idempotencyKey]);

        // First call completes
        $result1 = $this->paymentService->confirm($cmd1);

        // Need a new QR for the second call (same idempotency key)
        $qr2  = $this->makeStaticQr();
        $cmd2 = $this->makeCmd($qr2, ['idempotencyKey' => $idempotencyKey]);

        // Second call with same key should return existing result, not call ledger again
        $result2 = $this->paymentService->confirm($cmd2);

        $this->assertSame($result1->id, $result2->id);
    }

    public function test_step_up_required_at_threshold_missing_token(): void
    {
        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('get')->willReturnMap([
            ['qr.signing_key',           'test-confirm-key'],
            ['qr.dynamic_expiry_seconds', '7200'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',       'flat'],
            ['qr.payment.fee_value',      '100'],
            ['qr.payment.min_amount',     '100'],
            ['qr.payment.max_amount',     '100000000'],
            ['auth.step_up_threshold',    '100'], // threshold = 1 thebe = essentially everything
            ['ledger.fee_wallet.BWP',     'fee-ledger-ref'],
        ]);

        $signer = new QrPayloadSigner($this->config);
        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);

        $service = new QrPaymentService(
            $signer, $this->merchantRead, $this->wallet, $this->ledger,
            $this->config, $audit, $events, $this->receiptService
        );
        $codeService = new QrCodeService($signer, $this->merchantRead, $this->config, $audit);
        $qr = $codeService->generate(new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null), '00000000-0000-0000-0000-000000000003', 'c');

        $this->wallet->method('availableBalance')->willReturn(1_000_000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('lr');
        $this->ledger->expects($this->never())->method('postEntry');

        $this->expectException(StepUpRequiredException::class);
        $service->confirm($this->makeCmd($qr, ['stepUpToken' => null]));
    }

    public function test_static_qr_remains_active_after_successful_payment(): void
    {
        $qr = $this->makeStaticQr();

        $this->wallet->method('availableBalance')->willReturn(1_000_000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('ledger-ref');
        $this->ledger->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000103');

        $this->paymentService->confirm($this->makeCmd($qr));

        $qr->refresh();
        $this->assertSame(QrCodeStatus::Active, $qr->status);
    }

    public function test_dynamic_qr_transitions_to_used_after_successful_payment(): void
    {
        $qr = $this->makeDynamicQr('20.00');

        $this->wallet->method('availableBalance')->willReturn(1_000_000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('ledger-ref');
        $this->ledger->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000104');

        $this->paymentService->confirm($this->makeCmd($qr, ['amount' => '20.00']));

        $qr->refresh();
        $this->assertSame(QrCodeStatus::Used, $qr->status);
    }

    public function test_stale_reservation_is_cleared_before_status_check(): void
    {
        $qr                       = $this->makeStaticQr();
        $qr->status               = QrCodeStatus::Reserved;
        $qr->reserved_by_customer_id = '00000000-0000-0000-0000-000000000099';
        $qr->reserved_until       = now()->subSeconds(5);
        $qr->save();

        $this->wallet->method('availableBalance')->willReturn(1_000_000);
        $this->wallet->method('getPocketLedgerAccountRef')->willReturn('ledger-ref');
        $this->ledger->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000105');

        $result = $this->paymentService->confirm($this->makeCmd($qr));

        $this->assertSame(QrPaymentStatus::Completed, $result->status);
    }
}
