<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCustomerRelationshipModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'agent.agent_customer_relationships';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public function getMaskedNationalIdAttribute(): ?string
    {
        if (empty($this->national_id_number)) {
            return null;
        }
        return '****' . substr($this->national_id_number, -4);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_id');
    }
}
