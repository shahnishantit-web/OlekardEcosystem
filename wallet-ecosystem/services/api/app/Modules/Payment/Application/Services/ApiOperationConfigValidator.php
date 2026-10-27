<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\SsrfGuardInterface;
use App\Modules\Payment\Domain\Exceptions\SsrfViolationException;

/**
 * T043: Validates a single API operation configuration record before persistence.
 *
 * Guards:
 *  - operation_type is one of the allowed enum values (excludes payout)
 *  - transform_type values do not include executable/scripting identifiers
 *  - base_url passes SsrfGuard validation (HTTPS, no private IPs)
 *  - is_payout = false always
 *  - timeout_ms ≤ 120000
 *  - at least one of success_status_values or failure_status_values non-empty
 */
final class ApiOperationConfigValidator
{
    private const ALLOWED_OPERATION_TYPES = [
        'initiate', 'query', 'cancel', 'full_refund', 'partial_refund', 'callback', 'reconciliation',
    ];

    private const ALLOWED_TRANSFORM_TYPES = [
        'literal', 'json_path', 'constant', 'amount_minor', 'amount_decimal',
        'currency_code', 'uuid_v7', 'timestamp_iso8601', 'boolean_string',
    ];

    private const BLOCKED_TRANSFORM_TYPES = ['eval', 'exec', 'script', 'shell', 'code', 'php'];

    private const MAX_TIMEOUT_MS = 120_000;

    public function __construct(
        private readonly SsrfGuardInterface $ssrfGuard,
    ) {
    }

    /**
     * Validate one operation config array.
     *
     * @param array<string, mixed> $config
     * @return array<string, string>  field => error message; empty array on valid
     */
    public function validate(array $config): array
    {
        $errors = [];

        // operation_type
        $opType = $config['operation_type'] ?? '';

        if (!in_array($opType, self::ALLOWED_OPERATION_TYPES, true)) {
            $errors['operation_type'] = sprintf('operation_type must be one of: %s', implode(', ', self::ALLOWED_OPERATION_TYPES));
        }

        // is_payout must be false
        if (!empty($config['is_payout'])) {
            $errors['is_payout'] = 'Payout operations are not configurable via this endpoint.';
        }

        // base_url — SSRF guard
        $baseUrl = $config['base_url'] ?? '';

        if ($baseUrl !== '') {
            try {
                $this->ssrfGuard->validate($baseUrl);
            } catch (SsrfViolationException $e) {
                $errors['base_url'] = $e->getMessage();
            }
        } else {
            $errors['base_url'] = 'base_url is required.';
        }

        // http_method
        $method = strtoupper($config['http_method'] ?? '');

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true)) {
            $errors['http_method'] = 'http_method must be one of: GET, POST, PUT, PATCH';
        }

        // timeout_ms
        $timeout = (int) ($config['timeout_ms'] ?? 30000);

        if ($timeout > self::MAX_TIMEOUT_MS) {
            $errors['timeout_ms'] = sprintf('timeout_ms must not exceed %d ms', self::MAX_TIMEOUT_MS);
        }

        // field mapping transform_type validation
        foreach (['request_field_mappings', 'response_field_mappings'] as $mappingKey) {
            foreach (($config[$mappingKey] ?? []) as $i => $mapping) {
                $transformType = $mapping['transform_type'] ?? '';

                if (in_array(strtolower($transformType), self::BLOCKED_TRANSFORM_TYPES, true)) {
                    $errors["{$mappingKey}.{$i}.transform_type"] = sprintf(
                        'transform_type "%s" is not allowed (script injection risk)',
                        $transformType,
                    );
                } elseif (!in_array($transformType, self::ALLOWED_TRANSFORM_TYPES, true)) {
                    $errors["{$mappingKey}.{$i}.transform_type"] = sprintf(
                        'transform_type "%s" is not one of the allowed types',
                        $transformType,
                    );
                }
            }
        }

        // At least one of success/failure status values must be non-empty (except callback which may not need it)
        if ($opType !== 'callback') {
            $hasSuccess = !empty($config['success_status_values']) || !empty($config['failure_status_values']);

            if (!$hasSuccess) {
                $errors['success_status_values'] = 'At least one of success_status_values or failure_status_values must be non-empty.';
            }
        }

        return $errors;
    }
}
