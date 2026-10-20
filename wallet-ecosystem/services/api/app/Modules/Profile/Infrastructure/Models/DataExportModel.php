<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DataExportModel extends Model
{
    use HasUuids;

    protected $connection = 'profile';

    protected $table = 'profile.data_exports';

    protected $fillable = [
        'user_id',
        'status',
        'download_key',
        'expires_at',
        'requested_at',
        'completed_at',
        'correlation_id',
    ];

    protected $casts = [
        'expires_at'    => 'immutable_datetime',
        'requested_at'  => 'immutable_datetime',
        'completed_at'  => 'immutable_datetime',
        'created_at'    => 'immutable_datetime',
        'updated_at'    => 'immutable_datetime',
    ];
}
