<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Contracts;

interface WatchlistScreenerInterface
{
    /**
     * Screen a subject against the watchlist.
     *
     * @return array WatchlistMatch records created
     */
    public function screen(string $subjectType, string $subjectId, string $name, ?string $dob = null): array;

    public function screenRetrospectively(): void;
}
