<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment\Services;

use App\Modules\Payment\Application\Services\ApiOperationConfigValidator;
use App\Modules\Payment\Application\Services\SsrfGuard;
use PHPUnit\Framework\TestCase;

/**
 * T048: Unit tests for ApiOperationConfigValidator.
 */
final class ApiOperationConfigValidatorTest extends TestCase
{
    private ApiOperationConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ApiOperationConfigValidator(new SsrfGuard());
    }

    private function validConfig(array $overrides = []): array
    {
        return array_merge([
            'operation_type'        => 'initiate',
            'base_url'              => 'https://sandbox.bank.example.com',
            'endpoint_path'         => '/api/v1/pay',
            'http_method'           => 'POST',
            'auth_profile'          => 'bearer_token',
            'success_status_values' => ['COMPLETED'],
            'failure_status_values' => ['FAILED'],
            'timeout_ms'            => 30000,
            'is_payout'             => false,
        ], $overrides);
    }

    public function test_valid_config_passes(): void
    {
        $errors = $this->validator->validate($this->validConfig());

        $this->assertEmpty($errors, 'Valid config should produce no errors: '.json_encode($errors));
    }

    public function test_payout_operation_is_rejected(): void
    {
        $errors = $this->validator->validate($this->validConfig(['is_payout' => true]));

        $this->assertArrayHasKey('is_payout', $errors);
    }

    public function test_non_https_base_url_is_rejected(): void
    {
        $errors = $this->validator->validate($this->validConfig(['base_url' => 'http://bank.example.com']));

        $this->assertArrayHasKey('base_url', $errors);
        $this->assertStringContainsStringIgnoringCase('https', $errors['base_url']);
    }

    public function test_script_in_transform_type_is_rejected(): void
    {
        $errors = $this->validator->validate($this->validConfig([
            'request_field_mappings' => [
                ['source_field' => 'amount', 'target_field' => 'amt', 'transform_type' => 'eval', 'required' => true],
            ],
        ]));

        $this->assertArrayHasKey('request_field_mappings.0.transform_type', $errors);
    }

    public function test_exec_in_transform_type_is_rejected(): void
    {
        $errors = $this->validator->validate($this->validConfig([
            'response_field_mappings' => [
                ['source_field' => 'txnId', 'target_field' => 'ref', 'transform_type' => 'exec', 'required' => true],
            ],
        ]));

        $this->assertArrayHasKey('response_field_mappings.0.transform_type', $errors);
    }

    public function test_empty_status_mappings_rejected_for_non_callback(): void
    {
        $errors = $this->validator->validate($this->validConfig([
            'success_status_values' => [],
            'failure_status_values' => [],
        ]));

        $this->assertArrayHasKey('success_status_values', $errors);
    }

    public function test_callback_operation_can_have_empty_status_mappings(): void
    {
        $errors = $this->validator->validate($this->validConfig([
            'operation_type'        => 'callback',
            'success_status_values' => [],
            'failure_status_values' => [],
        ]));

        $this->assertArrayNotHasKey('success_status_values', $errors);
    }

    public function test_timeout_exceeding_120000_is_rejected(): void
    {
        $errors = $this->validator->validate($this->validConfig(['timeout_ms' => 150000]));

        $this->assertArrayHasKey('timeout_ms', $errors);
    }

    public function test_invalid_operation_type_rejected(): void
    {
        $errors = $this->validator->validate($this->validConfig(['operation_type' => 'payout']));

        $this->assertArrayHasKey('operation_type', $errors);
    }

    public function test_valid_literal_transform_type_passes(): void
    {
        $errors = $this->validator->validate($this->validConfig([
            'request_field_mappings' => [
                ['source_field' => 'payment_id', 'target_field' => 'ref', 'transform_type' => 'literal', 'required' => true],
            ],
        ]));

        $this->assertArrayNotHasKey('request_field_mappings.0.transform_type', $errors);
    }
}
