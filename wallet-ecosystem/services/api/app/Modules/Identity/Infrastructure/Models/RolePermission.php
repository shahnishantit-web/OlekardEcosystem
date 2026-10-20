<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RolePermission extends Model
{
    use HasUuids;

    protected $connection = 'identity';

    protected $table = 'identity.role_permissions';

    public $timestamps = false;

    protected $fillable = [
        'role_id',
        'permission_id',
        'created_by_actor_id',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
