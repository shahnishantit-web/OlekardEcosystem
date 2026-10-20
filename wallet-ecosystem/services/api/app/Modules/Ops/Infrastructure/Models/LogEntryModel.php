<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\LogSeverity;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $id
 * @property string      $service_name
 * @property string      $correlation_id
 * @property LogSeverity $severity
 * @property string      $message
 * @property array       $context
 * @property \Illuminate\Support\Carbon $created_at
 */
final class LogEntryModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.log_entries';
    public    $timestamps   = false;

    protected $fillable = [
        'id', 'service_name', 'correlation_id', 'severity', 'message', 'context', 'created_at',
    ];

    protected $casts = [
        'context'    => 'array',
        'severity'   => LogSeverity::class,
        'created_at' => 'datetime',
    ];
}
