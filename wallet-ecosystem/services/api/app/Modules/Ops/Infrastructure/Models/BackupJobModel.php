<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\BackupJobStatus;
use App\Modules\Ops\Domain\Enums\BackupType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                          $id
 * @property string                          $idempotency_key
 * @property BackupType                      $type
 * @property BackupJobStatus                 $status
 * @property string|null                     $storage_path
 * @property int|null                        $size_bytes
 * @property string|null                     $error_message
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class BackupJobModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.backup_jobs';

    protected $fillable = [
        'id', 'idempotency_key', 'type', 'status',
        'storage_path', 'size_bytes', 'error_message',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'type'         => BackupType::class,
        'status'       => BackupJobStatus::class,
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];
}
