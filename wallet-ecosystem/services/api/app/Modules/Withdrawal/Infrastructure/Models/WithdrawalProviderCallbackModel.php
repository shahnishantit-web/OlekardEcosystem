<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $withdrawal_transaction_id
 * @property string      $provider
 * @property string      $provider_reference_id
 * @property string      $status
 * @property array       $raw_payload
 * @property bool        $signature_valid
 * @property Carbon      $received_at
 * @property Carbon|null $processed_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class WithdrawalProviderCallbackModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'withdrawal.withdrawal_provider_callbacks';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'raw_payload'    => 'array',
            'signature_valid' => 'boolean',
            'received_at'    => 'datetime',
            'processed_at'   => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WithdrawalTransactionModel::class, 'withdrawal_transaction_id');
    }
}
