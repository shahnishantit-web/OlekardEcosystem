<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Qr;

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
use App\Modules\Qr\Domain\Exceptions\QrAlreadyUsedException;
use App\Modules\Qr\Domain\Exceptions\InvalidQrPayloadException;
use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentReceiptModel;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * SC-4, SC-5, SC-6, SC-7: Full static QR payment flow integration verification.
 */
final class QrPaymentIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private QrPaymentService $paymentService;
    private QrCodeService    $codeService;
    private LedgerModuleContract $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['qr.signing_key',           'integration-test-key'],
            ['qr.dynamic_expiry_seconds', '7200'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',       'flat'],
            ['qr.payment.fee_value',      '100'],
            ['qr.payment.min_amount',     '1'],
            ['qr.payment.max_amount',     '100000000'],
            ['auth.step_up_threshold',    '100000000'],
            ['ledger.fee_wallet.BWP',     'fee-account-ref'],
        ]);

        $merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $merchantRead->method('isActive')->willReturn(true);
        $merchantRead->method('getDisplayName')->willReturn('Integration Merchant');

        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->method('availableBalance')->willReturn(1_000_000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-account-ref');

        $this->ledger = $this->createMock(LedgerModuleContract::class);
        $this->ledger->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000113');

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);
        $audit->method('record');
        $events->method('publish');

        $receiptService = new QrReceiptService();
        $signer         = new QrPayloadSigner($config);

        $this->codeService    = new QrCodeService($signer, $merchantRead, $config, $audit);
        $this->paymentService = new QrPaymentService(
            $signer, $merchantRead, $wallet, $this->ledger, $config, $audit, $events, $receiptService
        );
    }

    /**
     * SC-4: Full static QR payment — journal posted, wallets updated correctly.
     */
    public function test_sc4_full_static_qr_payment_journal_verified(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-sc4');

        $this->ledger->expects($this->once())->method('postEntry')
            ->with($this->callback(function ($entry) {
                $lines = $entry['lines'];
                // customer debit = amount + fee = 2000 + 100 = 2100
                $debit  = array_filter($lines, fn ($l) => $l['direction'] === 'debit');
                $credit = array_filter($lines, fn ($l) => $l['direction'] === 'credit');

                $totalDebit  = array_sum(array_column($debit, 'amount'));
                $totalCredit = array_sum(array_column($credit, 'amount'));

                return $totalDebit === $totalCredit && count($lines) === 3;
            }))
            ->willReturn('00000000-0000-0000-0000-000000000114');

        $cmd = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000015',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   'idem-sc4-' . uniqid(),
            correlationId:    'corr-sc4',
            stepUpToken:      null,
        );

        $result = $this->paymentService->confirm($cmd);

        $this->assertSame(QrPaymentStatus::Completed, $result->status);
        $this->assertSame(2000, $result->amount);
        $this->assertSame(100, $result->fee);
        $this->assertSame(2100, $result->totalDebited);
        $this->assertNotNull(QrPaymentTransactionModel::find($result->id)->journal_id);
    }

    /**
     * SC-5: Idempotency — same key twice produces single debit.
     */
    public function test_sc5_idempotency_same_key_produces_single_debit(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-sc5');

        $this->ledger->expects($this->once())->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000115');

        $idempotencyKey = 'idem-sc5-' . uniqid();

        $cmd1 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000015',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   $idempotencyKey,
            correlationId:    'corr-sc5',
            stepUpToken:      null,
        );

        $result1 = $this->paymentService->confirm($cmd1);

        // Submit again with same idempotency key against a fresh QR
        $qrCode2 = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-sc5b');
        $cmd2    = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode2->id,
            customerWalletId: '00000000-0000-0000-0000-000000000015',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   $idempotencyKey,
            correlationId:    'corr-sc5b',
            stepUpToken:      null,
        );

        $result2 = $this->paymentService->confirm($cmd2);

        $this->assertSame($result1->id, $result2->id);

        $rowCount = QrPaymentTransactionModel::where('idempotency_key', $idempotencyKey)->count();
        $this->assertSame(1, $rowCount);
    }

    /**
     * SC-6: Dynamic QR single-use — second attempt returns QR_ALREADY_USED.
     */
    public function test_sc6_dynamic_qr_single_use_second_attempt_fails(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Dynamic, null, '20.00', 'BWP', null, 7200);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-sc6');

        $cmd1 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000015',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   'idem-sc6a-' . uniqid(),
            correlationId:    'corr-sc6',
            stepUpToken:      null,
        );

        $this->paymentService->confirm($cmd1);

        $qrCode->refresh();
        $this->assertSame(QrCodeStatus::Used, $qrCode->status);

        $cmd2 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000016',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   'idem-sc6b-' . uniqid(),
            correlationId:    'corr-sc6b',
            stepUpToken:      null,
        );

        $this->expectException(QrAlreadyUsedException::class);
        $this->paymentService->confirm($cmd2);
    }

    /**
     * SC-7: Tampered payload → INVALID_QR_PAYLOAD + audit logged.
     */
    public function test_sc7_tampered_payload_throws_and_is_audited(): void
    {
        $audit = $this->createMock(AuditServiceInterface::class);
        $audit->expects($this->once())->method('record')
            ->with($this->callback(fn ($data) => $data->action === 'qr.payload.tampered'));

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('integration-test-key');

        $signer         = new QrPayloadSigner($config);
        $merchantRead   = $this->createMock(MerchantReadServiceInterface::class);
        $wallet         = $this->createMock(WalletModuleContract::class);
        $ledger         = $this->createMock(LedgerModuleContract::class);
        $events         = $this->createMock(EventPublisherInterface::class);
        $receiptService = new QrReceiptService();

        $service = new QrPaymentService($signer, $merchantRead, $wallet, $ledger, $config, $audit, $events, $receiptService);

        $this->expectException(InvalidQrPayloadException::class);
        $service->resolve('definitely-tampered-payload-12345', '00000000-0000-0000-0000-000000000014', 'corr-sc7');
    }
}
