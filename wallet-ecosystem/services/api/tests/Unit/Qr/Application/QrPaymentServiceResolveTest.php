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
use App\Modules\Qr\Domain\DTO\GenerateQrCodeInput;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Domain\Exceptions\AmountOutOfRangeException;
use App\Modules\Qr\Domain\Exceptions\InvalidQrPayloadException;
use App\Modules\Qr\Domain\Exceptions\MerchantNotActiveException;
use App\Modules\Qr\Domain\Exceptions\QrAlreadyUsedException;
use App\Modules\Qr\Domain\Exceptions\QrExpiredException;
use App\Modules\Qr\Domain\Exceptions\QrNotActiveException;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class QrPaymentServiceResolveTest extends TestCase
{
    use DatabaseTransactions;

    private QrPaymentService $paymentService;
    private QrPayloadSigner  $signer;
    private MerchantReadServiceInterface $merchantRead;
    private ConfigurationServiceInterface $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->createMock(ConfigurationServiceInterface::class);
        $this->config->method('get')->willReturnMap([
            ['qr.signing_key',          'test-resolve-key'],
            ['qr.dynamic_expiry_seconds', '300'],
            ['qr.reservation_ttl_seconds', '120'],
            ['qr.payment.fee_type',     'flat'],
            ['qr.payment.fee_value',    '100'],
            ['qr.payment.min_amount',   '100'],
            ['qr.payment.max_amount',   '100000000'],
        ]);

        $this->merchantRead = $this->createMock(MerchantReadServiceInterface::class);
        $audit              = $this->createMock(AuditServiceInterface::class);
        $audit->method('record');
        $wallet  = $this->createMock(WalletModuleContract::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $events  = $this->createMock(EventPublisherInterface::class);
        $receipt = $this->createMock(QrReceiptService::class);

        $this->signer = new QrPayloadSigner($this->config);

        $codeService         = new QrCodeService($this->signer, $this->merchantRead, $this->config, $audit);
        $this->paymentService = new QrPaymentService(
            $this->signer, $this->merchantRead, $wallet, $ledger, $this->config, $audit, $events, $receipt
        );

        $this->codeService = $codeService;
    }

    private QrCodeService $codeService;

    private function makeStaticQr(): string
    {
        $this->merchantRead->method('isActive')->willReturn(true);
        $input = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        return $this->codeService->generate($input, '00000000-0000-0000-0000-000000000030', 'corr-x')->payload;
    }

    private function makeDynamicQr(string $amount = '20.00'): string
    {
        $input = new GenerateQrCodeInput(QrCodeType::Dynamic, null, $amount, 'BWP', null, 3600);
        return $this->codeService->generate($input, '00000000-0000-0000-0000-000000000030', 'corr-x')->payload;
    }

    public function test_resolve_valid_static_qr_returns_amount_required_true(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);
        $this->merchantRead->method('getDisplayName')->willReturn('Test Merchant');

        $payload = $this->makeStaticQr();

        $result = $this->paymentService->resolve($payload, '00000000-0000-0000-0000-000000000014', 'corr-001');

        $this->assertTrue($result->amountRequired);
        $this->assertNull($result->amount);
        $this->assertSame('Test Merchant', $result->merchantDisplayName);
    }

    public function test_resolve_valid_dynamic_qr_returns_preset_amount(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);
        $this->merchantRead->method('getDisplayName')->willReturn('Test Merchant');

        $payload = $this->makeDynamicQr('50.00');

        $result = $this->paymentService->resolve($payload, '00000000-0000-0000-0000-000000000014', 'corr-002');

        $this->assertFalse($result->amountRequired);
        $this->assertSame(5000, $result->amount);
    }

    public function test_resolve_tampered_payload_throws_and_audits(): void
    {
        $audit = $this->createMock(AuditServiceInterface::class);
        $audit->expects($this->once())->method('record')
            ->with($this->callback(fn ($data) => $data->action === 'qr.payload.tampered'));

        $wallet  = $this->createMock(WalletModuleContract::class);
        $ledger  = $this->createMock(LedgerModuleContract::class);
        $events  = $this->createMock(EventPublisherInterface::class);
        $receipt = $this->createMock(QrReceiptService::class);

        $service = new QrPaymentService(
            $this->signer, $this->merchantRead, $wallet, $ledger, $this->config, $audit, $events, $receipt
        );

        $this->expectException(InvalidQrPayloadException::class);
        $service->resolve('tampered-payload-xyz', '00000000-0000-0000-0000-000000000014', 'corr-003');
    }

    public function test_resolve_expired_dynamic_qr_throws_qr_expired(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);

        // Create dynamic QR and manually expire it in DB
        $input  = new GenerateQrCodeInput(QrCodeType::Dynamic, null, '20.00', 'BWP', null, 60);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000030', 'corr-x');
        $qrCode->expires_at = now()->subMinutes(5);
        $qrCode->save();

        $this->expectException(QrExpiredException::class);
        $this->paymentService->resolve($qrCode->payload, '00000000-0000-0000-0000-000000000014', 'corr-004');
    }

    public function test_resolve_inactive_merchant_throws_merchant_not_active(): void
    {
        $this->merchantRead->expects($this->exactly(2))
            ->method('isActive')
            ->willReturnOnConsecutiveCalls(true, false);

        $payload = $this->makeStaticQr();

        $this->expectException(MerchantNotActiveException::class);
        $this->paymentService->resolve($payload, '00000000-0000-0000-0000-000000000014', 'corr-005');
    }

    public function test_quote_in_range_returns_correct_fee_and_total(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000030', 'corr-x');

        $result = $this->paymentService->quote($qrCode->id, '20.00', 'BWP', 'corr-006');

        $this->assertSame(2000, $result->amount);
        $this->assertSame(100, $result->fee);
        $this->assertSame(2100, $result->total);
    }

    public function test_quote_below_minimum_throws_amount_out_of_range(): void
    {
        $this->merchantRead->method('isActive')->willReturn(true);
        $input  = new GenerateQrCodeInput(QrCodeType::Static, null, null, 'BWP', null, null);
        $qrCode = $this->codeService->generate($input, '00000000-0000-0000-0000-000000000030', 'corr-x');

        $this->expectException(AmountOutOfRangeException::class);
        $this->paymentService->quote($qrCode->id, '0.005', 'BWP', 'corr-007');
    }
}
