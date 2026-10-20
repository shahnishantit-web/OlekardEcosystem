<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Models;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Domain\Enums\FloatTransactionDirection;
use App\Modules\AgentFloat\Domain\Enums\FloatTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                    $id
 * @property string                    $agent_id
 * @property string                    $float_wallet_id
 * @property FloatTransactionType      $type
 * @property int                       $amount
 * @property string                    $currency_code
 * @property FloatTransactionDirection $direction
 * @property string|null               $related_party_id
 * @property string|null               $related_party_type
 * @property string                    $reference
 * @property string|null               $journal_id
 * @property string                    $idempotency_key
 * @property string                    $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class FloatTransactionModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'agent.float_transactions';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'type'      => FloatTransactionType::class,
        'direction' => FloatTransactionDirection::class,
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_id');
    }

    public function floatWallet(): BelongsTo
    {
        return $this->belongsTo(AgentFloatWalletModel::class, 'float_wallet_id');
    }
}
