<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Validators;

use App\Modules\Platform\Domain\Contracts\ConfigurationDomainValidatorInterface;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry;
use Illuminate\Validation\ValidationException;

final class OperationalPolicyConfigValidator implements ConfigurationDomainValidatorInterface
{
    private const float WEIGHT_SUM_TOLERANCE = 0.001;

    public function supportsDomain(ConfigurationDomain $domain): bool
    {
        return $domain === ConfigurationDomain::OperationalPolicy;
    }

    public function validate(string $key, mixed $value): void
    {
        if (SecurityPolicyRegistry::contains($key)) {
            SecurityPolicyRegistry::validate($key, $value);

            return;
        }
        $scalar = $this->extractScalar($value);

        if ($this->isKycRuleKey($key)) {
            $this->validateKycRuleStruct($key, $scalar);

            return;
        }

        if ($this->isRiskWeightKey($key)) {
            $this->validateRiskWeights($key, $scalar);

            return;
        }

        if ($this->isNotificationTemplateKey($key)) {
            $this->validateNotificationTemplate($key, $scalar);

            return;
        }
    }

    private function extractScalar(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value) && count($value) === 1) {
            return $value['value'];
        }

        return $value;
    }

    private function isKycRuleKey(string $key): bool
    {
        return str_contains($key, 'kyc_rule') || str_contains($key, 'kyc.rule');
    }

    private function isRiskWeightKey(string $key): bool
    {
        return str_contains($key, 'risk_weight') || str_contains($key, 'risk.weight');
    }

    private function isNotificationTemplateKey(string $key): bool
    {
        return str_contains($key, 'notification_template') || str_contains($key, 'notification.template');
    }

    private function validateKycRuleStruct(string $key, mixed $value): void
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                $key => "KYC rule key '{$key}' must be a JSON object.",
            ]);
        }

        $requiredFields = ['document_types', 'min_age'];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $value)) {
                throw ValidationException::withMessages([
                    $key => "KYC rule key '{$key}' is missing required field '{$field}'.",
                ]);
            }
        }
    }

    private function validateRiskWeights(string $key, mixed $value): void
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                $key => "Risk weight key '{$key}' must be a JSON object mapping factors to weights.",
            ]);
        }

        $sum = 0.0;
        foreach ($value as $factor => $weight) {
            if (! is_numeric($weight)) {
                throw ValidationException::withMessages([
                    $key => "Risk weight key '{$key}': factor '{$factor}' must be numeric.",
                ]);
            }
            $sum += (float) $weight;
        }

        if (abs($sum - 1.0) > self::WEIGHT_SUM_TOLERANCE) {
            throw ValidationException::withMessages([
                $key => "Risk weight key '{$key}': weights must sum to 1.0 (actual: {$sum}).",
            ]);
        }
    }

    private function validateNotificationTemplate(string $key, mixed $value): void
    {
        if (! is_string($value) && ! (is_array($value) && isset($value['body']))) {
            throw ValidationException::withMessages([
                $key => "Notification template key '{$key}' must be a string or object with a 'body' field.",
            ]);
        }

        $body = is_string($value) ? $value : (string) ($value['body'] ?? '');

        // Detect required placeholders by parsing the key name for known template types
        $requiredPlaceholders = $this->getRequiredPlaceholders($key);

        foreach ($requiredPlaceholders as $placeholder) {
            if (! str_contains($body, $placeholder)) {
                throw ValidationException::withMessages([
                    $key => "Notification template '{$key}' is missing required placeholder '{$placeholder}'.",
                ]);
            }
        }
    }

    /** @return string[] */
    private function getRequiredPlaceholders(string $key): array
    {
        if (str_contains($key, 'otp')) {
            return ['{{otp_code}}'];
        }

        if (str_contains($key, 'transfer')) {
            return ['{{amount}}', '{{recipient}}'];
        }

        if (str_contains($key, 'kyc')) {
            return ['{{user_name}}'];
        }

        return [];
    }
}
