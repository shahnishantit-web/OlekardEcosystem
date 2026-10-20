<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\ValueObjects;

final readonly class VirusScanResult
{
    public function __construct(
        private bool    $clean,
        public ?string  $threatName = null,
    ) {}

    public function isClean(): bool
    {
        return $this->clean;
    }
}
