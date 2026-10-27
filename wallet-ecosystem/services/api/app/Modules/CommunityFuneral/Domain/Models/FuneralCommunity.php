<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class FuneralCommunity extends Model
{
    protected $connection = 'community_services';
    protected $table = 'community_services.funeral_communities';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'organisation_id',
        'display_name',
        'status',
        'policy_version',
        'created_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
