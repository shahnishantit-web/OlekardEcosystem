<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Repositories;

use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Exceptions\AlertRuleNotFoundException;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EloquentAlertRuleRepository implements AlertRuleRepositoryInterface
{
    public function create(array $attributes): AlertRuleModel
    {
        return AlertRuleModel::create(array_merge(['id' => Str::orderedUuid()->toString()], $attributes));
    }

    public function update(string $id, array $attributes): AlertRuleModel
    {
        $rule = $this->findOrFail($id);
        $rule->update($attributes);
        return $rule->refresh();
    }

    public function delete(string $id): void
    {
        $this->findOrFail($id)->delete();
    }

    public function findOrFail(string $id): AlertRuleModel
    {
        return AlertRuleModel::find($id) ?? throw new AlertRuleNotFoundException($id);
    }

    public function allEnabled(): Collection
    {
        return AlertRuleModel::where('is_enabled', true)->get();
    }

    public function all(): Collection
    {
        return AlertRuleModel::orderByDesc('created_at')->get();
    }
}
