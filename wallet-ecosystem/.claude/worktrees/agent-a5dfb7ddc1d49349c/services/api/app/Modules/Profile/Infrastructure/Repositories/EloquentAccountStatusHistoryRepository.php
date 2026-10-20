<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Repositories;

use App\Modules\Profile\Domain\Entities\AccountStatusHistory;
use App\Modules\Profile\Infrastructure\Models\AccountStatusHistoryModel;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

final class EloquentAccountStatusHistoryRepository
{
    /** @param array<string, mixed> $attributes */
    public function appendTransition(array $attributes): AccountStatusHistoryModel
    {
        $model             = new AccountStatusHistoryModel();
        $model->id         = (string) Uuid::uuid7();
        $model->user_id    = $attributes['user_id'];
        $model->from_status = $attributes['from_status'] ?? null;
        $model->to_status  = $attributes['to_status'];
        $model->reason     = $attributes['reason'] ?? null;
        $model->actor_id   = $attributes['actor_id'];
        $model->actor_type = $attributes['actor_type'];
        $model->closure_obligations = $attributes['closure_obligations'] ?? null;
        $model->correlation_id      = $attributes['correlation_id'];
        $model->created_at = now();
        $model->save();
        return $model;
    }

    /** @return Collection<int, AccountStatusHistoryModel> */
    public function getHistoryForUser(string $userId): Collection
    {
        return AccountStatusHistoryModel::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc') // UUID v7 secondary tiebreaker; monotonically increasing per host
            ->get();
    }

    /**
     * Return a list of domain entities for read-only consumption.
     *
     * @return list<AccountStatusHistory>
     */
    public function getHistoryEntitiesForUser(string $userId): array
    {
        return $this->getHistoryForUser($userId)
            ->map(fn (AccountStatusHistoryModel $m) => AccountStatusHistory::fromArray([
                'id'                  => $m->id,
                'user_id'             => $m->user_id,
                'from_status'         => $m->from_status,
                'to_status'           => $m->to_status,
                'reason'              => $m->reason,
                'actor_id'            => $m->actor_id,
                'actor_type'          => $m->actor_type,
                'closure_obligations' => $m->closure_obligations,
                'correlation_id'      => $m->correlation_id,
                'created_at'          => $m->created_at->toIso8601String(),
            ]))
            ->values()
            ->all();
    }
}
