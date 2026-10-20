<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects\Providers;

use App\Modules\Platform\Domain\Enums\ProviderCapabilityEnum;

final class ProviderCapabilitySet
{
    /** @var ProviderCapabilityEnum[] */
    private readonly array $capabilities;

    private function __construct(ProviderCapabilityEnum ...$caps)
    {
        $seen = [];
        $deduped = [];
        foreach ($caps as $cap) {
            if (!isset($seen[$cap->value])) {
                $seen[$cap->value] = true;
                $deduped[] = $cap;
            }
        }
        $this->capabilities = $deduped;
    }

    public static function of(ProviderCapabilityEnum ...$caps): self
    {
        return new self(...$caps);
    }

    public static function empty(): self
    {
        return new self();
    }

    public function has(ProviderCapabilityEnum $cap): bool
    {
        foreach ($this->capabilities as $c) {
            if ($c === $cap) {
                return true;
            }
        }
        return false;
    }

    /** @return ProviderCapabilityEnum[] */
    public function all(): array
    {
        return $this->capabilities;
    }

    public function isEmpty(): bool
    {
        return $this->capabilities === [];
    }

    public function count(): int
    {
        return count($this->capabilities);
    }
}
