<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use PHPUnit\Framework\TestCase;

final class SensitiveFieldRedactionTest extends TestCase
{
    private PaymentProviderAdminService $service;

    protected function setUp(): void
    {
        $this->service = new PaymentProviderAdminService(
            audit: $this->createMock(AuditServiceInterface::class),
        );
    }

    public function test_safe_provider_shape_excludes_api_key_fields(): void
    {
        $provider = new PaymentProviderModel();
        $provider->id                         = 'provider-uuid-001';
        $provider->code                       = 'mock_bank';
        $provider->display_name               = 'Mock Bank';
        $provider->adapter_identifier         = 'mock_bank';
        $provider->environment                = 'sandbox';
        $provider->is_enabled                 = true;
        $provider->is_suspended               = false;
        $provider->is_default                 = false;
        $provider->sort_order                 = 1;
        // Simulate that the model has these sensitive attributes set
        $provider->setAttribute('callback_secret_reference', 'vault/secrets/mock_bank/callback');
        $provider->setAttribute('merchant_account_reference', 'vault/secrets/mock_bank/merchant');
        $provider->setAttribute('api_key', 'sk_test_supersecret123');

        $shape = $this->service->safeProviderShape($provider);

        // Required display fields must be present
        $this->assertArrayHasKey('id', $shape);
        $this->assertArrayHasKey('code', $shape);
        $this->assertArrayHasKey('display_name', $shape);
        $this->assertArrayHasKey('adapter_identifier', $shape);
        $this->assertArrayHasKey('environment', $shape);
        $this->assertArrayHasKey('is_enabled', $shape);

        // Sensitive fields must NOT be present
        $serialized = json_encode($shape);
        $this->assertIsString($serialized);

        foreach ($shape as $key => $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/api.key/i',
                (string) $key,
                "Key '{$key}' looks like an API key field"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/secret/i',
                (string) $key,
                "Key '{$key}' looks like a secret field"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/webhook_sign/i',
                (string) $key,
                "Key '{$key}' looks like a webhook signing field"
            );
        }
    }

    public function test_safe_provider_shape_does_not_leak_vault_paths(): void
    {
        $provider = new PaymentProviderModel();
        $provider->id               = 'provider-uuid-002';
        $provider->code             = 'bank_x';
        $provider->display_name     = 'Bank X';
        $provider->adapter_identifier = 'bank_x';
        $provider->environment      = 'production';
        $provider->is_enabled       = true;
        $provider->is_suspended     = false;
        $provider->is_default       = true;
        $provider->sort_order       = 1;

        $shape      = $this->service->safeProviderShape($provider);
        $serialized = (string) json_encode($shape);

        // Vault path patterns must not appear in any value
        $this->assertStringNotContainsStringIgnoringCase('vault/', $serialized);
        $this->assertStringNotContainsStringIgnoringCase('secret/', $serialized);
    }
}
