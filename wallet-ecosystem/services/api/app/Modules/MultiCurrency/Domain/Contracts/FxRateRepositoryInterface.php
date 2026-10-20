<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Contracts;

use App\Modules\MultiCurrency\Infrastructure\Models\FxRateModel;
use Illuminate\Support\Collection;

interface FxRateRepositoryInterface
{
    public function findById(string $id): ?FxRateModel;

    public function findByChangeRequestId(string $changeRequestId): ?FxRateModel;

    public function findActivePair(string $from, string $to): ?FxRateModel;

    public function listActive(): Collection;

    public function listAll(): Collection;

    public function save(FxRateModel $model): void;

    /** Marks the currently active rate for the pair as superseded. */
    public function supersedeCurrentRate(string $from, string $to): void;

    /** @deprecated Use supersedeCurrentRate() — kept for backward compatibility */
    public function deactivateCurrentRate(string $from, string $to): void;
}
