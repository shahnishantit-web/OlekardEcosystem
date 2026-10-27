<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

final class StatementAlreadyExistsException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $existingStatementId,
        public readonly string $sourceHash,
    ) {
        parent::__construct($message);
    }

    public static function withHash(string $hash, string $existingId): self
    {
        return new self(
            "A provider statement with source_hash '{$hash}' already exists (id: {$existingId}).",
            existingStatementId: $existingId,
            sourceHash:          $hash,
        );
    }
}
