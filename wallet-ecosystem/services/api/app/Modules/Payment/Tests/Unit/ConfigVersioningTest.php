<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Domain\Exceptions\ConfigurationVersionConflictException;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use PHPUnit\Framework\TestCase;

final class ConfigVersioningTest extends TestCase
{
    private PaymentProviderAdminService $service;

    protected function setUp(): void
    {
        $auditMock     = $this->createMock(AuditServiceInterface::class);
        $this->service = new PaymentProviderAdminService($auditMock);
    }

    public function test_draft_validation_passes_for_valid_secret_reference(): void
    {
        $errors = $this->service->validateDraftPayload([
            'callback_secret_reference' => 'vault://secret/payment/callback-key',
        ]);

        self::assertEmpty($errors);
    }

    public function test_draft_validation_fails_for_invalid_secret_reference(): void
    {
        $errors = $this->service->validateDraftPayload([
            'callback_secret_reference' => 'plaintext-password',
        ]);

        self::assertArrayHasKey('callback_secret_reference', $errors);
        self::assertSame('INVALID_SECRET_REFERENCE_FORMAT', $errors['callback_secret_reference']);
    }

    public function test_draft_validation_fails_when_callback_url_present(): void
    {
        $errors = $this->service->validateDraftPayload([
            'callback_url' => 'https://external-bank.example.com/callback',
        ]);

        self::assertArrayHasKey('callback_url', $errors);
        self::assertSame('CALLBACK_URL_NOT_PERMITTED', $errors['callback_url']);
    }

    public function test_draft_validation_fails_for_unknown_currency(): void
    {
        $errors = $this->service->validateDraftPayload([
            'supported_currencies' => ['BWP', 'INVALID_CODE'],
        ]);

        self::assertArrayHasKey('supported_currencies', $errors);
        self::assertStringContainsString('INVALID_CURRENCY_CODE', $errors['supported_currencies']);
    }

    public function test_draft_validation_fails_for_unknown_capability_flag(): void
    {
        $errors = $this->service->validateDraftPayload([
            'capability_flags' => ['supports_teleportation' => true],
        ]);

        self::assertArrayHasKey('capability_flags', $errors);
        self::assertStringContainsString('UNKNOWN_CAPABILITY_FLAG', $errors['capability_flags']);
    }

    public function test_draft_validation_fails_for_unknown_instrument_type(): void
    {
        $errors = $this->service->validateDraftPayload([
            'supported_payment_methods' => ['wallet_transfer', 'quantum_payment'],
        ]);

        self::assertArrayHasKey('supported_payment_methods', $errors);
        self::assertStringContainsString('UNKNOWN_INSTRUMENT_TYPE', $errors['supported_payment_methods']);
    }

    public function test_draft_validation_passes_for_valid_currencies(): void
    {
        $errors = $this->service->validateDraftPayload([
            'supported_currencies' => ['BWP', 'USD', 'ZAR'],
        ]);

        self::assertEmpty($errors);
    }

    public function test_multiple_validation_errors_returned_together(): void
    {
        $errors = $this->service->validateDraftPayload([
            'callback_url'              => 'https://external.example.com',
            'callback_secret_reference' => 'plaintext',
        ]);

        self::assertArrayHasKey('callback_url', $errors);
        self::assertArrayHasKey('callback_secret_reference', $errors);
    }
}
