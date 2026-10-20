<?php

declare(strict_types=1);

namespace Tests\Unit\Qr\Application;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Qr\Application\Services\QrPayloadSigner;
use App\Modules\Qr\Application\Services\QrPaymentService;
use App\Modules\Qr\Application\Services\QrReceiptService;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Qr\Domain\Exceptions\InvalidQrPayloadException;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Tests\TestCase;

final class QrPayloadSignerTest extends TestCase
{
    private QrPayloadSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('test-signing-key-abc123');

        $this->signer = new QrPayloadSigner($config);
    }

    public function test_sign_verify_round_trip_static_qr(): void
    {
        $fields = [
            'type'        => 'static',
            'merchant_id' => '00000000-0000-0000-0000-000000000003',
            'currency'    => 'BWP',
            'nonce'       => '550e8400-e29b-41d4-a716-446655440000',
        ];

        $encoded = $this->signer->sign($fields);
        $decoded = $this->signer->verify($encoded);

        $this->assertSame('static', $decoded['type']);
        $this->assertSame('00000000-0000-0000-0000-000000000003', $decoded['merchant_id']);
        $this->assertSame('BWP', $decoded['currency']);
        $this->assertArrayNotHasKey('amount', $decoded);
    }

    public function test_sign_verify_round_trip_dynamic_qr_with_amount(): void
    {
        $fields = [
            'type'        => 'dynamic',
            'merchant_id' => '00000000-0000-0000-0000-000000000003',
            'currency'    => 'BWP',
            'amount'      => 5000,
            'nonce'       => '550e8400-e29b-41d4-a716-446655440001',
            'expires_at'  => '2026-12-31T23:59:59+00:00',
        ];

        $encoded = $this->signer->sign($fields);
        $decoded = $this->signer->verify($encoded);

        $this->assertSame('dynamic', $decoded['type']);
        $this->assertSame(5000, $decoded['amount']);
        $this->assertSame('2026-12-31T23:59:59+00:00', $decoded['expires_at']);
    }

    public function test_tampered_payload_throws_invalid_qr_payload_exception(): void
    {
        $fields = [
            'type'        => 'static',
            'merchant_id' => '00000000-0000-0000-0000-000000000003',
            'currency'    => 'BWP',
            'nonce'       => '550e8400-e29b-41d4-a716-446655440002',
        ];

        $encoded = $this->signer->sign($fields);

        // Decode, tamper, re-encode without correct signature
        $json    = base64_decode(str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) + (4 - strlen($encoded) % 4) % 4, '='), true);
        $payload = json_decode($json, true);
        $payload['merchant_id'] = 'attacker-wallet';
        $tampered = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $this->expectException(InvalidQrPayloadException::class);
        $this->signer->verify($tampered);
    }

    public function test_label_field_is_not_included_in_signed_data(): void
    {
        $fieldsWithLabel    = ['type' => 'static', 'merchant_id' => 'mid', 'currency' => 'BWP', 'nonce' => 'nonce-a', 'label' => 'My Store'];
        $fieldsWithoutLabel = ['type' => 'static', 'merchant_id' => 'mid', 'currency' => 'BWP', 'nonce' => 'nonce-a'];

        $encodedWith    = $this->signer->sign($fieldsWithLabel);
        $encodedWithout = $this->signer->sign($fieldsWithoutLabel);

        // Both should be verifiable and label should be absent from decoded data
        $decodedWith    = $this->signer->verify($encodedWith);
        $decodedWithout = $this->signer->verify($encodedWithout);

        $this->assertArrayNotHasKey('label', $decodedWith);
        $this->assertArrayNotHasKey('label', $decodedWithout);
    }

    public function test_null_fields_are_excluded_from_canonical_json(): void
    {
        $fields = [
            'type'         => 'static',
            'merchant_id'  => 'mid',
            'currency'     => 'BWP',
            'nonce'        => 'nonce-b',
            'amount'       => null,
            'merchant_ref' => null,
        ];

        $encoded = $this->signer->sign($fields);
        $decoded = $this->signer->verify($encoded);

        $this->assertArrayNotHasKey('amount', $decoded);
        $this->assertArrayNotHasKey('merchant_ref', $decoded);
    }

    public function test_verify_throws_on_completely_invalid_base64(): void
    {
        $this->expectException(InvalidQrPayloadException::class);
        $this->signer->verify('not-valid-base64!!!@@@');
    }

    /**
     * T060: When QrPaymentService::resolve() receives a tampered payload, the
     *       signer throws InvalidQrPayloadException and the service records an
     *       audit entry with action='qr.payload.tampered'.
     */
    public function test_tampered_payload_triggers_audit_in_payment_service(): void
    {
        $config = $this->createMock(ConfigurationServiceInterface::class);
        $config->method('get')->willReturn('test-signing-key-abc123');

        $audit = $this->createMock(AuditServiceInterface::class);
        $audit->expects($this->once())
              ->method('record')
              ->with($this->callback(fn ($data) => ($data->action ?? null) === 'qr.payload.tampered'));

        $signer         = new QrPayloadSigner($config);
        $merchantRead   = $this->createMock(MerchantReadServiceInterface::class);
        $wallet         = $this->createMock(WalletModuleContract::class);
        $ledger         = $this->createMock(LedgerModuleContract::class);
        $events         = $this->createMock(EventPublisherInterface::class);
        $receiptService = $this->createMock(QrReceiptService::class);

        $service = new QrPaymentService($signer, $merchantRead, $wallet, $ledger, $config, $audit, $events, $receiptService);

        $this->expectException(InvalidQrPayloadException::class);
        $service->resolve('definitely-tampered-payload-xyz', '00000000-0000-0000-0000-000000000014', 'corr-t060');
    }
}
