<?php

declare(strict_types=1);

namespace Tests\Unit\Qr\Application;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Qr\Application\Services\QrCodeService;
use App\Modules\Qr\Application\Services\QrPayloadSigner;
use App\Modules\Qr\Application\Services\QrReceiptService;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Qr\Domain\DTO\GenerateQrCodeInput;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use App\Modules\Qr\Domain\Exceptions\ReceiptNotAvailableException;
use App\Modules\Qr\Infrastructure\Models\QrPaymentTransactionModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QrReceiptServiceTest extends TestCase
{
    use DatabaseTransactions;

    private QrReceiptService $receiptService;
    private QrCodeService    $codeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptService = new QrReceiptService();

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['qr.signing_key',           'test-receipt-key'],
            ['qr.dynamic_expiry_seconds', '300'],
        ]);

        $merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $merchantRead->method('isActive')->willReturn(true);

        $audit = $this->createMock(AuditServiceInterface::class);
        $signer = new QrPayloadSigner($config);

        $this->codeService = new QrCodeService($signer, $merchantRead, $config, $audit);
    }

    private function createCompletedTransaction(): QrPaymentTransactionModel
    {
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-x');

        $tx                     = new QrPaymentTransactionModel();
        $tx->id                 = Str::orderedUuid()->toString();
        $tx->idempotency_key    = 'idem-receipt-' . uniqid();
        $tx->correlation_id     = 'corr-receipt-' . uniqid();
        $tx->qr_code_id         = $qrCode->id;
        $tx->customer_wallet_id = '00000000-0000-0000-0000-000000000015';
        $tx->merchant_wallet_id = '00000000-0000-0000-0000-000000000003';
        $tx->amount             = 2000;
        $tx->fee                = 100;
        $tx->currency           = 'BWP';
        $tx->status             = QrPaymentStatus::Completed;
        $tx->step_up_verified   = false;
        $tx->save();

        return $tx;
    }

    public function test_create_receipt_has_all_correct_fields(): void
    {
        $tx      = $this->createCompletedTransaction();
        $receipt = $this->receiptService->createForTransaction($tx, 'Test Merchant');

        $this->assertNotEmpty($receipt->receipt_ref);
        $this->assertSame($tx->id, $receipt->qr_payment_transaction_id);
        $this->assertSame('Test Merchant', $receipt->merchant_display_name);
        $this->assertSame(2000, $receipt->amount);
        $this->assertSame(100, $receipt->fee);
        $this->assertSame(2100, $receipt->total_debited);
        $this->assertSame('BWP', $receipt->currency);
    }

    public function test_receipt_ref_starts_with_qrpmt_prefix(): void
    {
        $tx      = $this->createCompletedTransaction();
        $receipt = $this->receiptService->createForTransaction($tx, 'Test Merchant');

        $this->assertStringStartsWith('QRPMT-', $receipt->receipt_ref);
    }

    public function test_total_debited_equals_amount_plus_fee(): void
    {
        $tx      = $this->createCompletedTransaction();
        $receipt = $this->receiptService->createForTransaction($tx, 'Test Merchant');

        $this->assertSame($receipt->amount + $receipt->fee, $receipt->total_debited);
    }

    public function test_find_for_customer_returns_same_receipt_on_repeated_calls(): void
    {
        $tx = $this->createCompletedTransaction();
        $this->receiptService->createForTransaction($tx, 'Test Merchant');

        $receipt1 = $this->receiptService->findForCustomer($tx->id, '00000000-0000-0000-0000-000000000015');
        $receipt2 = $this->receiptService->findForCustomer($tx->id, '00000000-0000-0000-0000-000000000015');

        $this->assertSame($receipt1->id, $receipt2->id);
        $this->assertSame($receipt1->receipt_ref, $receipt2->receipt_ref);
    }

    public function test_find_for_customer_throws_for_initiated_transaction(): void
    {
        $tx         = $this->createCompletedTransaction();
        $tx->status = QrPaymentStatus::Initiated;
        $tx->save();

        $this->expectException(ReceiptNotAvailableException::class);
        $this->receiptService->findForCustomer($tx->id, '00000000-0000-0000-0000-000000000015');
    }

    public function test_find_for_customer_throws_for_failed_transaction(): void
    {
        $tx         = $this->createCompletedTransaction();
        $tx->status = QrPaymentStatus::Failed;
        $tx->save();

        $this->expectException(ReceiptNotAvailableException::class);
        $this->receiptService->findForCustomer($tx->id, '00000000-0000-0000-0000-000000000015');
    }
}
