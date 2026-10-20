<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain\Contracts;

interface SecurityAuditSource
{
    /** @param array<string,mixed> $filters @param array<string,string>|null $cursor @return list<array<string,mixed>> */
    public function events(array $filters, ?array $cursor, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function findingTimeline(string $id): array;
}
