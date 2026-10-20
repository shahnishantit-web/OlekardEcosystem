<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Exceptions;

use RuntimeException;

final class WebhookNotDeadLetterException extends RuntimeException
{
    public static function forEvent(string $webhookEventId, string $currentStatus): self
    {
        return new self(
            "Webhook event {$webhookEventId} cannot be re-queued: current status is '{$currentStatus}', expected 'dead_letter'."
        );
    }
}
