<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use Closure;

/** Current accepted terms snapshot for a paid Events order; implementations must not use stale cache. */
interface OleKardTermsAcceptanceQuery
{
    /** @return array<string, string>|null */
    public function currentAcceptanceSnapshot(string $organisationId, string $eventId): ?array;

    /** Run Events order/payment writes while serialized against terms publication. */
    public function withPublicationGuard(Closure $operation): mixed;
}
