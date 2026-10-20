<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $job_id
 * @property string $format
 * @property string $status
 * @property string|null $storage_path
 * @property int|null $file_size_bytes
 * @property int|null $row_count
 * @property string|null $download_token
 * @property \Illuminate\Support\Carbon|null $token_expires_at
 * @property \Illuminate\Support\Carbon|null $file_retention_expires_at
 * @property \Illuminate\Support\Carbon|null $purged_at
 * @property string|null $created_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ReportExportModel extends Model
{
    protected $connection  = 'platform';
    protected $table       = 'reporting.report_exports';
    protected $keyType     = 'string';
    public    $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'job_id', 'format', 'status', 'storage_path', 'file_size_bytes',
        'row_count', 'download_token', 'token_expires_at',
        'file_retention_expires_at', 'purged_at', 'created_by_user_id',
    ];

    protected $casts = [
        'token_expires_at'          => 'datetime',
        'file_retention_expires_at' => 'datetime',
        'purged_at'                 => 'datetime',
        'created_at'                => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model): void {
            $model->id         ??= (string) Uuid::uuid7();
            $model->created_at ??= now();
        });
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ReportJobModel::class, 'job_id');
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    public function isLinkExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function isFileRetained(): bool
    {
        return !$this->isPurged()
            && $this->file_retention_expires_at !== null
            && $this->file_retention_expires_at->isFuture();
    }
}
