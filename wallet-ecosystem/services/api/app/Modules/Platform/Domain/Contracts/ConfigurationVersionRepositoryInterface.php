<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\Enums\VersionEventType;
use Illuminate\Pagination\LengthAwarePaginator;

interface ConfigurationVersionRepositoryInterface
{
    /**
     * Inserts a new immutable version record.
     * Uses DB insert directly to bypass the immutable model guard.
     *
     * @param array<string, mixed> $attributes
     */
    public function insert(array $attributes): void;

    /**
     * @param string|null $from      ISO-8601 lower bound for occurred_at
     * @param string|null $to        ISO-8601 upper bound for occurred_at
     * @param string|null $makerId   Filter by maker_id
     * @param string|null $checkerId Filter by checker_id
     * @param string|null $domain    Filter by domain (financial_policy|operational_policy|geographic)
     */
    public function paginateByKey(
        string  $configKey,
        int     $perPage,
        ?string $from      = null,
        ?string $to        = null,
        ?string $makerId   = null,
        ?string $checkerId = null,
        ?string $domain    = null,
    ): LengthAwarePaginator;

    /** @return array<int, array<string, mixed>> */
    public function findByChangeRequest(string $changeRequestId): array;
}
