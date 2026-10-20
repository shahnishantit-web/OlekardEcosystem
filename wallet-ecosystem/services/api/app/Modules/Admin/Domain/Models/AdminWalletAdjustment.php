<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Models;

use App\Modules\Admin\Domain\Enums\AdjustmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AdminWalletAdjustment extends Model
{
    protected $connection  = 'admin';
    protected $table       = 'admin.admin_wallet_adjustments';
    public    $incrementing = false;
    protected $keyType     = 'string';

    protected $fillable = [
        'id', 'idempotency_key', 'wallet_id', 'adjustment_type',
        'amount', 'currency', 'reason', 'status', 'maker_id',
        'checker_id', 'ledger_transaction_id', 'rejection_reason',
        'approved_at', 'posted_at', 'rejected_at',
    ];

    protected $casts = [
        'status'      => AdjustmentStatus::class,
        'approved_at' => 'datetime',
        'posted_at'   => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', AdjustmentStatus::PendingApproval->value);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', AdjustmentStatus::Posted->value);
    }
}
