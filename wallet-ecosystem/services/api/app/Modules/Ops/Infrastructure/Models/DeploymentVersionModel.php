<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                          $id
 * @property string                          $version
 * @property string|null                     $git_sha
 * @property string|null                     $release_notes
 * @property string|null                     $deployed_by
 * @property \Illuminate\Support\Carbon      $deployed_at
 * @property bool                            $is_current
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class DeploymentVersionModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.deployment_versions';

    protected $fillable = [
        'id', 'version', 'git_sha', 'release_notes', 'deployed_by', 'deployed_at', 'is_current',
    ];

    protected $casts = [
        'deployed_at' => 'datetime',
        'is_current'  => 'boolean',
    ];

    public function rollbacks(): HasMany
    {
        return $this->hasMany(DeploymentRollbackModel::class, 'from_version', 'version');
    }
}
