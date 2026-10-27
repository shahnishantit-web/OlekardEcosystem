<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use App\Modules\Payment\Domain\ValueObjects\PaymentProviderOption;
use PHPUnit\Framework\TestCase;

/**
 * T075: Unit tests for ProviderRegistryService consumer options enrichment.
 * Verifies P12 display metadata fields are present and no credentials leak.
 * DB-backed scenarios are Docker-gated.
 */
final class ProviderRegistryServiceOptionsTest extends TestCase
{
    public function test_payment_provider_option_has_display_label_field(): void
    {
        $option = new PaymentProviderOption(
            providerCode: 'fnbz',
            displayName:  'FNB Botswana',
            isWallet:     false,
            isDefault:    false,
            sortOrder:    1,
            displayLabel: 'Pay via FNB',
        );

        $this->assertSame('Pay via FNB', $option->displayLabel);
    }

    public function test_payment_provider_option_has_payment_method_field(): void
    {
        $option = new PaymentProviderOption(
            providerCode:  'fnbz',
            displayName:   'FNB Botswana',
            isWallet:      false,
            isDefault:     false,
            sortOrder:     1,
            paymentMethod: 'bank_redirect',
        );

        $this->assertSame('bank_redirect', $option->paymentMethod);
    }

    public function test_payment_provider_option_has_display_order_default(): void
    {
        $option = new PaymentProviderOption(
            providerCode: 'fnbz',
            displayName:  'FNB Botswana',
            isWallet:     false,
            isDefault:    false,
            sortOrder:    1,
        );

        $this->assertSame(0, $option->displayOrder);
    }

    public function test_payment_provider_option_has_no_credential_fields(): void
    {
        $option = new PaymentProviderOption(
            providerCode: 'fnbz',
            displayName:  'FNB Botswana',
            isWallet:     false,
            isDefault:    false,
            sortOrder:    1,
        );

        $serialised = json_encode($option);

        $this->assertStringNotContainsString('secret', (string) $serialised);
        $this->assertStringNotContainsString('Bearer', (string) $serialised);
        $this->assertStringNotContainsString('sk_', (string) $serialised);
        $this->assertStringNotContainsString('api_key', (string) $serialised);
    }

    public function test_suspended_account_absent_from_options(): void
    {
        $this->markTestSkipped('Docker-gated: requires running DB with suspended account + ProviderRegistryService');
    }

    public function test_display_label_from_consumer_payment_option_record(): void
    {
        $this->markTestSkipped('Docker-gated: requires ConsumerPaymentOptionModel with seeded data');
    }

    public function test_no_credential_fields_in_serialised_option(): void
    {
        $this->markTestSkipped('Docker-gated: full serialisation test requires service integration');
    }
}
