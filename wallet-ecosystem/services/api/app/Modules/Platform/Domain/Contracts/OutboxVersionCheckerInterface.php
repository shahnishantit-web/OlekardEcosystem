<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface OutboxVersionCheckerInterface
{
    /**
     * Returns true if the outbox row was dead-lettered (caller should return early).
     * Returns false if the version is supported or the eventId is absent.
     *
     * @param array<string> $supportedVersions
     */
    public function checkAndDeadLetterIfUnsupported(?string $eventId, array $supportedVersions): bool;
}
