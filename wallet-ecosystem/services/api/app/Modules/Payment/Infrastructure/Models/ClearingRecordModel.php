<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $payment_attempt_id
 * @property string      $statement_id
 * @property string      $statement_line_id
 * @property string      $provider_code
 * @property string      $currency_code
 * @property int         $cleared_amount_minor
 * @property int         $attempt_amount_minor
 * @property string      $clearing_status   ('cleared' | 'bank_settled')
 * @property string      $accounting_status ('pending_ledger_config' | 'qualified' | 'excluded')
 * @property string|null $bank_settlement_batch_ref
 * @property \Illuminate\Support\Carbon|null $bank_settled_at
 * @property \Illuminate\Support\Carbon|null $aged_at
 * @property \Illuminate\Support\Carbon $cleared_at
 */
final class ClearingRecordModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.clearing_records';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payment_attempt_id',
        'statement_id',
        'statement_line_id',
        'provider_code',
        'currency_code',
        'cleared_amount_minor',
        'attempt_amount_minor',
        'clearing_status',
        'accounting_status',
        'bank_settlement_batch_ref',
        'bank_settled_at',
        'aged_at',
        'cleared_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cleared_amount_minor' => 'integer',
            'attempt_amount_minor' => 'integer',
            'cleared_at'           => 'immutable_datetime',
            'bank_settled_at'      => 'immutable_datetime',
            'aged_at'              => 'immutable_datetime',
            'created_at'           => 'immutable_datetime',
            'updated_at'           => 'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttemptModel::class, 'payment_attempt_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(ProviderStatementModel::class, 'statement_id');
    }

    /** @param Builder<ClearingRecordModel> $query */
    public function scopeCleared(Builder $query): void
    {
        $query->where('clearing_status', 'cleared');
    }

    /** @param Builder<ClearingRecordModel> $query */
    public function scopeBankSettled(Builder $query): void
    {
        $query->where('clearing_status', 'bank_settled');
    }
}
