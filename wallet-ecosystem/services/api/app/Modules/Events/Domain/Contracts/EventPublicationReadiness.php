<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Contracts;

/** Must verify all policy and service prerequisites for the supplied event categories. */
interface EventPublicationReadiness
{
    /** @param array<string, mixed> $event @param array<int, array<string, mixed>> $categories */
    public function assertPublishable(array $event, array $categories): void;
}
