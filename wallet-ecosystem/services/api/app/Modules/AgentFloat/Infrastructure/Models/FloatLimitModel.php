<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Models;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $agent_id
 * @property string      $currency_code
 * @property int|null    $max_balance
 * @property bool        $max_balance_is_tbd
 * @property int|null    $per_transaction_limit
 * @property bool        $per_tx_is_tbd
 * @property int|null    $daily_volume_limit
 * @property bool        $daily_vol_is_tbd
 * @property int|null    $low_float_threshold
 * @property bool        $low_threshold_is_tbd
 * @property bool        $low_float_alerted
 * @property \Illuminate\Support\Carbon|null $effective_from
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FloatLimitModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'agent.float_limits';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'max_balance_is_tbd'   => 'boolean',
        'per_tx_is_tbd'        => 'boolean',
        'daily_vol_is_tbd'     => 'boolean',
        'low_threshold_is_tbd' => 'boolean',
        'low_float_alerted'    => 'boolean',
        'effective_from'       => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_id');
    }
}
