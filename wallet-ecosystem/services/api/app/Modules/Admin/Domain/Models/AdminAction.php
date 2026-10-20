<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Models;

use App\Modules\Admin\Domain\Enums\AdminActionType;
use Illuminate\Database\Eloquent\Model;

final class AdminAction extends Model
{
    protected $connection  = 'admin';
    protected $table       = 'admin.admin_actions';
    public    $incrementing = false;
    protected $keyType     = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'action_type', 'actor_id', 'subject_type', 'subject_id',
        'metadata', 'reason', 'ip_address', 'user_agent', 'correlation_id', 'performed_at',
    ];

    protected $casts = [
        'action_type'  => AdminActionType::class,
        'metadata'     => 'array',
        'performed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \RuntimeException('AdminAction is append-only: updates are prohibited (constitution P4).');
        });

        static::deleting(static function (): void {
            throw new \RuntimeException('AdminAction is append-only: deletes are prohibited (constitution P4).');
        });
    }
}
