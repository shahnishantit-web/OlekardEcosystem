<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use JsonSerializable;

/**
 * Stores a reference to a secret manager key name only — never the raw secret value.
 * Serialises as {"key": "...", "masked": "****"} in all contexts.
 */
final readonly class SecretReference implements JsonSerializable
{
    private const RAW_SECRET_PATTERNS = [
        '/^Bearer\s+/',
        '/^sk_/',
        '/^pk_/',
    ];

    private const BASE64_MIN_LENGTH = 40;

    public string $masked;

    public function __construct(public string $keyName)
    {
        if (trim($keyName) === '') {
            throw new \InvalidArgumentException('SecretReference keyName must not be empty.');
        }

        $this->guardAgainstRawSecretPattern($keyName);

        $this->masked = '****';
    }

    public static function fromKeyName(string $keyName): self
    {
        return new self($keyName);
    }

    /** @return array{key: string, masked: string} */
    public function jsonSerialize(): array
    {
        return ['key' => $this->keyName, 'masked' => $this->masked];
    }

    public function equals(self $other): bool
    {
        return $this->keyName === $other->keyName;
    }

    private function guardAgainstRawSecretPattern(string $value): void
    {
        foreach (self::RAW_SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                throw new \InvalidArgumentException('SecretReference value appears to contain a raw credential. Pass the secret manager key name, not the secret value.');
            }
        }

        // Reject suspiciously long base64-like strings that are likely raw values
        if (
            strlen($value) > self::BASE64_MIN_LENGTH
            && preg_match('/^[A-Za-z0-9+\/=_-]{40,}$/', $value)
            && !str_contains($value, '/')  // allow path-like key names: payments/fnbz/sandbox/key
        ) {
            throw new \InvalidArgumentException('SecretReference value appears to be a raw credential (long base64 string). Pass the secret manager key name instead.');
        }
    }
}
