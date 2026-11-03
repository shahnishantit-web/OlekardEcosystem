<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventPublicationReadiness;
use Illuminate\Validation\ValidationException;

final class DenyEventPublicationReadiness implements EventPublicationReadiness
{
    public function assertPublishable(array $event, array $categories): void
    {
        throw ValidationException::withMessages([
            'publication' => 'No production event-publication readiness contract is configured.',
        ]);
    }
}
