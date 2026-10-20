<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Models;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use Database\Factories\AgentFloatWalletModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $agent_id
 * @property string $wallet_id
 * @property string $ledger_account_reference
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class AgentFloatWalletModel extends Model
{
    use HasFactory;

    protected static function newFactory(): AgentFloatWalletModelFactory
    {
        return AgentFloatWalletModelFactory::new();
    }

    protected $connection = 'platform';
    protected $table      = 'agent.float_wallets';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AgentModel::class, 'agent_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FloatTransactionModel::class, 'float_wallet_id');
    }
}
