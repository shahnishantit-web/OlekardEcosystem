<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

final readonly class IdempotencyResult
{
    private function __construct(
        public bool  $isReplay,
        public bool  $isConflict,
        public int   $responseStatus,
        public array $responseBody,
    ) {
    }

    public static function replay(int $status, array $body): self
    {
        return new self(isReplay: true, isConflict: false, responseStatus: $status, responseBody: $body);
    }

    public static function conflict(): self
    {
        return new self(isReplay: false, isConflict: true, responseStatus: 0, responseBody: []);
    }

    public static function proceed(): self
    {
        return new self(isReplay: false, isConflict: false, responseStatus: 0, responseBody: []);
    }
}
