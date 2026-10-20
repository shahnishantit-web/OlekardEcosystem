<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Permission extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $connection = 'identity';

    protected $table = 'identity.permissions';

    protected $fillable = [
        'slug',
        'display_name',
        'description',
        'module',
        'is_system_permission',
    ];

    protected $casts = [
        'is_system_permission' => 'boolean',
        'deleted_at'           => 'immutable_datetime',
    ];
}
