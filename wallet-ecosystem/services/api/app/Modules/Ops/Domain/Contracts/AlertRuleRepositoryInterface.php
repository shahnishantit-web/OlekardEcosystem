<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use Illuminate\Support\Collection;

interface AlertRuleRepositoryInterface
{
    public function create(array $attributes): AlertRuleModel;

    public function update(string $id, array $attributes): AlertRuleModel;

    public function delete(string $id): void;

    public function findOrFail(string $id): AlertRuleModel;

    /** @return Collection<int, AlertRuleModel> */
    public function allEnabled(): Collection;

    /** @return Collection<int, AlertRuleModel> */
    public function all(): Collection;
}
