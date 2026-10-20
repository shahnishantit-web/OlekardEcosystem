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
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * T069: Verify QR payment transactions are surfaced in E11 transaction history.
 */
final class QrTransactionHistoryIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private QrPaymentService $paymentService;
    private QrCodeService    $codeService;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['qr.signing_key',           'history-test-key'],
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
        $merchantRead->method('getDisplayName')->willReturn('History Merchant');

        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->method('availableBalance')->willReturn(1_000_000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000112');

        $audit  = $this->createMock(AuditServiceInterface::class);
        $events = $this->createMock(EventPublisherInterface::class);

        $receiptService = new QrReceiptService();
        $signer         = new QrPayloadSigner($config);

        $this->codeService    = new QrCodeService($signer, $merchantRead, $config, $audit);
        $this->paymentService = new QrPaymentService(
            $signer, $merchantRead, $wallet, $ledger, $config, $audit, $events, $receiptService
        );
    }

    public function test_completed_qr_payment_is_stored_with_correct_schema(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-history');

        $cmd = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000015',
            amount:           '20.00',
            currency:         'BWP',
            idempotencyKey:   'idem-history-' . uniqid(),
            correlationId:    'corr-history',
            stepUpToken:      null,
        );

        $result = $this->paymentService->confirm($cmd);

        // Verify the transaction is persisted with E11-compatible column names
        /** @var QrPaymentTransactionModel $tx */
        $tx = QrPaymentTransactionModel::where('id', $result->id)->first();

        $this->assertNotNull($tx);
        $this->assertSame(QrPaymentStatus::Completed, $tx->status);
        $this->assertNotEmpty($tx->idempotency_key);
        $this->assertNotEmpty($tx->correlation_id);
        $this->assertNotEmpty($tx->qr_code_id);
        $this->assertNotEmpty($tx->customer_wallet_id);
        $this->assertNotEmpty($tx->merchant_wallet_id);
        $this->assertGreaterThan(0, $tx->amount);
        $this->assertNotEmpty($tx->currency);
        $this->assertNotNull($tx->journal_id);
        $this->assertNotNull($tx->created_at);
    }

    public function test_qr_payment_table_is_queryable_by_customer_wallet(): void
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-x');

        $customerWalletId = (string)\Illuminate\Support\Str::orderedUuid();

        $cmd = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: $customerWalletId,
            amount:           '30.00',
            currency:         'BWP',
            idempotencyKey:   'idem-hist2-' . uniqid(),
            correlationId:    'corr-hist2',
            stepUpToken:      null,
        );

        $this->paymentService->confirm($cmd);

        $count = QrPaymentTransactionModel::where('customer_wallet_id', $customerWalletId)->count();
        $this->assertSame(1, $count);
    }
}
