<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $change_request_id
 * @property string $checker_id
 * @property string $decision
 * @property string|null $comments
 * @property string $idempotency_key
 * @property Carbon $decided_at
 */
final class ConfigurationApprovalModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.configuration_approvals';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'change_request_id',
        'checker_id',
        'decision',
        'comments',
        'idempotency_key',
        'decided_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<ConfigurationChangeRequestModel, ConfigurationApprovalModel> */
    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ConfigurationChangeRequestModel::class, 'change_request_id');
    }
}
