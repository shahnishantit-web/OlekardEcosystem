<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Contracts;

readonly class PhotoStorageResult
{
    public function __construct(
        public string $storageKey,
        public int    $sizeBytes,
        public string $mimeType,
        public int    $widthPx,
        public int    $heightPx,
    ) {}
}
