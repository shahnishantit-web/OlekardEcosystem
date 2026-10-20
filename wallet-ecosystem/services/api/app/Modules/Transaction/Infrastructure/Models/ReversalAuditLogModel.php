<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $reversal_id
 * @property string      $transaction_id
 * @property string      $action
 * @property string|null $actor_id
 * @property string|null $actor_type
 * @property array|null  $metadata
 * @property Carbon      $occurred_at
 */
class ReversalAuditLogModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transaction.reversal_audit_log';

    public $incrementing = false;
    public $timestamps   = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'metadata'    => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        if ($this->exists) {
            throw new \RuntimeException('Reversal audit log entries are immutable.');
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->exists) {
            throw new \RuntimeException('Reversal audit log entries are immutable.');
        }

        parent::performDeleteOnModel();
    }
}
