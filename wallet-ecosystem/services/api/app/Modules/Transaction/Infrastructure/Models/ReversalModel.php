<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Models;

use App\Modules\Transaction\Domain\Enums\ReversalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string              $id
 * @property string              $transaction_id
 * @property ReversalStatus      $status
 * @property string              $requested_by
 * @property Carbon              $requested_at
 * @property string|null         $approved_by
 * @property Carbon|null         $approved_at
 * @property string|null         $rejected_by
 * @property string|null         $rejected_reason
 * @property Carbon|null         $executed_at
 * @property string|null         $compensating_journal_id
 * @property string              $idempotency_key
 * @property string|null         $correlation_id
 * @property Carbon              $created_at
 * @property Carbon              $updated_at
 */
class ReversalModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transaction.reversals';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'status'        => ReversalStatus::class,
            'requested_at'  => 'datetime',
            'approved_at'   => 'datetime',
            'executed_at'   => 'datetime',
        ];
    }

    public function auditLog(): HasMany
    {
        return $this->hasMany(ReversalAuditLogModel::class, 'reversal_id')
            ->orderBy('occurred_at', 'asc');
    }
}
