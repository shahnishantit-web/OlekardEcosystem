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
use App\Modules\Qr\Domain\Exceptions\MerchantNotActiveException;
use App\Modules\Qr\Domain\Exceptions\QrNotActiveException;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class QrCodeServiceTest extends TestCase
{
    use DatabaseTransactions;

    private QrCodeService $service;
    private MerchantReadServiceInterface $merchantRead;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['qr.signing_key', 'test-key-for-qr-service'],
            ['qr.dynamic_expiry_seconds', '300'],
        ]);

        $this->merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $audit              = $this->createMock(AuditServiceInterface::class);
        $audit->method('record');

        $signer        = new QrPayloadSigner($config);
        $this->service = new QrCodeService($signer, $this->merchantRead, $config, $audit);
    }

    public function test_generate_static_qr_has_no_amount_and_no_expiry(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);

        $input = new GenerateQrCodeInput(
            type:          QrCodeType::Static,
            label:         'Main counter',
            amount:        null,
            currency:      'BWP',
            merchantRef:   null,
            expirySeconds: null,
        );

        $qrCode = $this->service->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-001');

        $this->assertSame(QrCodeType::Static, $qrCode->type);
        $this->assertSame(QrCodeStatus::Active, $qrCode->status);
        $this->assertNull($qrCode->amount);
        $this->assertNull($qrCode->expires_at);
        $this->assertNotEmpty($qrCode->payload);
    }

    public function test_generate_dynamic_qr_has_amount_and_expiry(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);

        $input = new GenerateQrCodeInput(
            type:          QrCodeType::Dynamic,
            label:         null,
            amount:        '50.00',
            currency:      'BWP',
            merchantRef:   'INV-001',
            expirySeconds: 600,
        );

        $qrCode = $this->service->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-002');

        $this->assertSame(QrCodeType::Dynamic, $qrCode->type);
        $this->assertSame(5000, $qrCode->amount);
        $this->assertNotNull($qrCode->expires_at);
        $this->assertTrue($qrCode->expires_at->isFuture());
    }

    public function test_deactivate_own_qr_transitions_to_inactive(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);

        $input = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->service->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-003');

        $deactivated = $this->service->deactivate($qrCode->id, '00000000-0000-0000-0000-000000000003', 'corr-003');

        $this->assertSame(QrCodeStatus::Inactive, $deactivated->status);
    }

    public function test_deactivate_different_merchants_qr_throws_qr_not_active(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);

        $input = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->service->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-004');

        $this->expectException(QrNotActiveException::class);
        $this->service->deactivate($qrCode->id, '00000000-0000-0000-0000-000000000031', 'corr-004');
    }

    public function test_inactive_merchant_throws_merchant_not_active(): void
    {
        $this->merchantRead->method('isActive')->willReturn(false);

        $input = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);

        $this->expectException(MerchantNotActiveException::class);
        $this->service->generate($input, 'inactive-merchant', 'corr-005');
    }

    /**
     * T061: After a completed payment, a static QR returns to 'active' and
     *       can be used for a subsequent payment.
     */
    public function test_static_qr_remains_active_after_payment_and_can_be_reused(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);
        $this->merchantRead->method('getDisplayName')->willReturn('Test Merchant');

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['qr.signing_key',            'test-key-for-qr-service'],
            ['qr.dynamic_expiry_seconds', '300'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',       'flat'],
            ['qr.payment.fee_value',      '0'],
            ['qr.payment.min_amount',     '1'],
            ['qr.payment.max_amount',     '100000000'],
            ['auth.step_up_threshold',    '100000000'],
            ['ledger.fee_wallet.BWP',     'fee-ref'],
        ]);

        $signer  = new QrPayloadSigner($config);
        $audit   = $this->createMock(AuditServiceInterface::class);
        $service = new QrCodeService($signer, $this->merchantRead, $config, $audit);

        $wallet = $this->createMock(WalletModuleContract::class);
        $wallet->method('availableBalance')->willReturn(1_000_000);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('pocket-ref');

        $ledger = $this->createMock(LedgerModuleContract::class);
        $ledger->method('postEntry')->willReturn('00000000-0000-0000-0000-000000000111');

        $events         = $this->createMock(EventPublisherInterface::class);
        $receiptService = new QrReceiptService();

        $paymentService = new QrPaymentService($signer, $this->merchantRead, $wallet, $ledger, $config, $audit, $events, $receiptService);

        // Generate static QR
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $service->generate($input, '00000000-0000-0000-0000-000000000003', 'corr-t061');

        // First payment
        $cmd1 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000015',
            amount:           '10.00',
            currency:         'BWP',
            idempotencyKey:   'idem-t061-a-' . uniqid(),
            correlationId:    'corr-t061a',
            stepUpToken:      null,
        );

        $paymentService->confirm($cmd1);

        // After first payment, static QR must be active again
        $qrCode->refresh();
        $this->assertSame(QrCodeStatus::Active, $qrCode->status);

        // Second payment on the same QR must succeed
        $cmd2 = new ExecuteQrPaymentCommand(
            qrCodeId:         $qrCode->id,
            customerWalletId: '00000000-0000-0000-0000-000000000016',
            amount:           '10.00',
            currency:         'BWP',
            idempotencyKey:   'idem-t061-b-' . uniqid(),
            correlationId:    'corr-t061b',
            stepUpToken:      null,
        );

        $result2 = $paymentService->confirm($cmd2);

        $qrCode->refresh();
        $this->assertSame(QrCodeStatus::Active, $qrCode->status);
        $this->assertSame(\App\Modules\Qr\Domain\Enums\QrPaymentStatus::Completed, $result2->status);
    }
}
