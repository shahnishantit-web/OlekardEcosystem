<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventPublicationReadiness;
use Illuminate\Validation\ValidationException;

/** Synthetic publication evidence for an explicitly enabled local POC only. */
final class LocalPocEventPublicationReadiness implements EventPublicationReadiness
{
    public function assertPublishable(array $event, array $categories): void
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true,
            503, 'The local Events POC is disabled.');

        if ($categories === []) {
            throw ValidationException::withMessages(['categories' => 'At least one ticket category is required.']);
        }
        $hasPaidCategories = false;
        foreach ($categories as $category) {
            if (($category['allocation_type'] ?? 'paid') !== 'paid') continue;
            $hasPaidCategories = true;
            if ((int) ($category['price_minor'] ?? 0) < 1) {
                throw ValidationException::withMessages(['categories' => 'POC paid tickets require a positive demo price.']);
            }
        }
        if ($hasPaidCategories && (($event['currency_code'] ?? null) === null || ($event['currency_assignment_id'] ?? null) === null)) {
            throw ValidationException::withMessages(['currency_code' => 'Assign a supported event currency before POC publication.']);
        }
    }
}
