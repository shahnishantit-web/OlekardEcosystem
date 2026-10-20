<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform;

use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Validators\GeographicConfigValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * T052 — GeographicConfigValidator unit tests.
 */
final class GeographicConfigValidatorTest extends UnitTestCase
{
    private GeographicConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new GeographicConfigValidator();
    }

    public function test_supports_geographic_domain(): void
    {
        $this->assertTrue($this->validator->supportsDomain(ConfigurationDomain::Geographic));
    }

    public function test_does_not_support_other_domains(): void
    {
        $this->assertFalse($this->validator->supportsDomain(ConfigurationDomain::FinancialPolicy));
        $this->assertFalse($this->validator->supportsDomain(ConfigurationDomain::OperationalPolicy));
    }

    public function test_valid_bwp_currency_code_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('geographic.default_currency', 'BWP');
    }

    public function test_invalid_currency_code_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('geographic.default_currency', 'XYZ');
    }

    public function test_valid_zw_country_code_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('geographic.default_country', 'ZW');
    }

    public function test_invalid_country_code_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('geographic.default_country', 'XX');
    }

    public function test_valid_currencies_array_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('geographic.supported_currencies', ['ZAR', 'BWP', 'KES']);
    }

    public function test_currencies_array_with_invalid_code_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('geographic.supported_currencies', ['ZAR', 'XYZ']);
    }

    public function test_duplicate_active_currency_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('geographic.supported_currencies', ['ZAR', 'BWP', 'ZAR']);
    }

    public function test_valid_countries_array_passes(): void
    {
        $this->expectNotToPerformAssertions();
        $this->validator->validate('geographic.supported_countries', ['ZA', 'BW', 'KE']);
    }

    public function test_countries_array_with_invalid_code_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('geographic.supported_countries', ['ZA', 'XX']);
    }

    public function test_duplicate_country_code_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate('geographic.supported_countries', ['ZA', 'BW', 'ZA']);
    }
}
