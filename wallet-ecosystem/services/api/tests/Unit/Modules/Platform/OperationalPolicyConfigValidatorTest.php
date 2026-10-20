<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform;

use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Validators\OperationalPolicyConfigValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * T048 — OperationalPolicyConfigValidator unit tests.
 */
final class OperationalPolicyConfigValidatorTest extends UnitTestCase
{
    private OperationalPolicyConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new OperationalPolicyConfigValidator();
    }

    public function test_supports_operational_policy_domain(): void
    {
        $this->assertTrue($this->validator->supportsDomain(ConfigurationDomain::OperationalPolicy));
    }

    public function test_does_not_support_other_domains(): void
    {
        $this->assertFalse($this->validator->supportsDomain(ConfigurationDomain::FinancialPolicy));
        $this->assertFalse($this->validator->supportsDomain(ConfigurationDomain::Geographic));
    }

    // KYC rule validation
    public function test_valid_kyc_rule_struct_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('operational_policy.kyc_rule.individual', [
            'document_types' => ['passport', 'id_card'],
            'min_age'        => 18,
        ]);
    }

    public function test_kyc_rule_missing_document_types_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('operational_policy.kyc_rule.individual', [
            'min_age' => 18,
        ]);
    }

    public function test_kyc_rule_not_array_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('operational_policy.kyc_rule.individual', 'invalid_string');
    }

    // Risk weight validation
    public function test_risk_weights_summing_to_one_pass(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('operational_policy.risk_weight.factors', [
            'amount'     => 0.4,
            'velocity'   => 0.3,
            'geolocation' => 0.3,
        ]);
    }

    public function test_risk_weights_not_summing_to_one_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('operational_policy.risk_weight.factors', [
            'amount'   => 0.5,
            'velocity' => 0.3,
        ]);
    }

    public function test_risk_weight_with_non_numeric_value_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('operational_policy.risk_weight.factors', [
            'amount'   => 'high',
            'velocity' => 0.5,
        ]);
    }

    // Notification template validation
    public function test_otp_template_with_required_placeholder_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate(
            'operational_policy.notification_template.otp',
            'Your OTP is {{otp_code}}. Valid for 5 minutes.'
        );
    }

    public function test_otp_template_missing_placeholder_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(
            'operational_policy.notification_template.otp',
            'Your code is 123456.'
        );
    }
}
