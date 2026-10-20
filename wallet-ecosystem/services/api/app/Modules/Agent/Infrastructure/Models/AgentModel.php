<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Models;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Domain\Enums\AgentType;
use Database\Factories\AgentModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string               $id
 * @property string               $user_id
 * @property AgentStatus          $status
 * @property AgentType            $agent_type
 * @property string|null          $agent_tier
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $suspended_at
 * @property \Illuminate\Support\Carbon|null $reactivated_at
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon      $updated_at
 */
class AgentModel extends Model
{
    use HasFactory;

    protected static function newFactory(): AgentModelFactory
    {
        return AgentModelFactory::new();
    }

    protected $connection = 'platform';

    protected $table = 'agent.agents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'         => AgentStatus::class,
        'agent_type'     => AgentType::class,
        'approved_at'    => 'datetime',
        'suspended_at'   => 'datetime',
        'reactivated_at' => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(AgentApplicationModel::class, 'application_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AgentStatusHistoryModel::class, 'agent_id')
            ->orderBy('occurred_at', 'desc');
    }

    public function customerRelationships(): HasMany
    {
        return $this->hasMany(AgentCustomerRelationshipModel::class, 'agent_id');
    }
}
