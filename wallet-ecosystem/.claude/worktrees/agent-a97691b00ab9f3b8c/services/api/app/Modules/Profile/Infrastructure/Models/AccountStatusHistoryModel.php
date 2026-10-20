<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Append-only audit trail — no update() calls permitted at the application layer.
final class AccountStatusHistoryModel extends Model
{
    use HasUuids;

    protected $connection = 'profile';

    protected $table = 'profile.account_status_history';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'actor_type',
        'closure_obligations',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'closure_obligations' => 'array',
        'created_at'          => 'immutable_datetime',
    ];
}
