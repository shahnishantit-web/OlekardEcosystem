<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform;

use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Validators\FinancialPolicyConfigValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * T037 — FinancialPolicyConfigValidator unit tests.
 */
final class FinancialPolicyConfigValidatorTest extends UnitTestCase
{
    private FinancialPolicyConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FinancialPolicyConfigValidator();
    }

    public function test_supports_financial_policy_domain(): void
    {
        $this->assertTrue($this->validator->supportsDomain(ConfigurationDomain::FinancialPolicy));
    }

    public function test_does_not_support_other_domains(): void
    {
        $this->assertFalse($this->validator->supportsDomain(ConfigurationDomain::OperationalPolicy));
        $this->assertFalse($this->validator->supportsDomain(ConfigurationDomain::Geographic));
    }

    public function test_valid_positive_limit_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('financial_policy.max_single_transfer', 10000);
    }

    public function test_valid_positive_limit_in_value_wrapper_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('financial_policy.max_single_transfer', ['value' => 10000]);
    }

    public function test_negative_limit_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('financial_policy.max_single_transfer', -100);
    }

    public function test_zero_limit_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('financial_policy.max_single_transfer', 0);
    }

    public function test_non_numeric_limit_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('financial_policy.max_single_transfer', 'not_a_number');
    }

    public function test_valid_fee_rate_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('financial_policy.transfer_fee_rate', 2.5);
    }

    public function test_fee_rate_above_100_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('financial_policy.transfer_fee_rate', 101);
    }

    public function test_fee_rate_below_zero_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('financial_policy.transfer_fee_rate', -1);
    }

    public function test_zero_fee_rate_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('financial_policy.transfer_fee_rate', 0);
    }

    public function test_valid_iso_4217_currency_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('financial_policy.default_currency', 'USD');
    }

    public function test_invalid_currency_code_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('financial_policy.default_currency', 'XYZ');
    }
}
