<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\ValueObjects;

final readonly class SnapshotDigest
{
    public function __construct(public string $value)
    {
        if (strlen($this->value) !== 64 || ! ctype_xdigit($this->value)) {
            throw new \InvalidArgumentException('Snapshot digest must be a 64-character SHA-256 hex string.');
        }
    }
}
