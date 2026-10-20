<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class PendingAdminAction extends Model
{
    protected $table = 'pending_admin_actions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'action_type',
        'resource_type',
        'resource_id',
        'payload',
        'maker_id',
        'checker_id',
        'status',
        'idempotency_key',
        'correlation_id',
        'rejection_reason',
        'expires_at',
        'resolved_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'expires_at'   => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() && $this->status === 'pending_approval';
    }
}
