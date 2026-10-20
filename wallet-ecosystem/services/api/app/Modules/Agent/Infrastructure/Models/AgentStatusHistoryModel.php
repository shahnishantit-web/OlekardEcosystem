<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Models;

use App\Modules\Agent\Domain\Exceptions\AgentStatusHistoryImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentStatusHistoryModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'agent.agent_status_history';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_id');
    }

    protected function performUpdate(Builder $query): bool
    {
        if ($this->exists) {
            throw new AgentStatusHistoryImmutableException();
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->exists) {
            throw new AgentStatusHistoryImmutableException();
        }

        parent::performDeleteOnModel();
    }
}
