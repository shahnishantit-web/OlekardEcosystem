<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Models;

use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Domain\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentApplicationModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'agent.agent_applications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'        => ApplicationStatus::class,
        'agent_type'    => AgentType::class,
        'personal_info' => 'array',
        'business_info' => 'array',
        'submitted_at'  => 'datetime',
        'kyc_initiated_at' => 'datetime',
        'approved_at'   => 'datetime',
        'rejected_at'   => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AgentDocumentModel::class, 'application_id');
    }

    public function agent(): HasOne
    {
        return $this->hasOne(AgentModel::class, 'application_id');
    }
}
