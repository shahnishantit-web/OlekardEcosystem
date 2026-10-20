<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Logging;

final class LogSanitizer
{
    private const BLOCKED_PATTERN = '/password|pin|secret|token|card|cvv|otp|ssn|auth/i';

    public function sanitize(array $context): array
    {
        return $this->sanitizeArray($context);
    }

    private function sanitizeArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::BLOCKED_PATTERN, $key)) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->sanitizeArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
