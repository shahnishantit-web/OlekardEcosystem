<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Models;

use App\Modules\CommunityFuneral\Domain\Enums\RepresentativeStatus;
use Illuminate\Database\Eloquent\Model;

final class FuneralRepresentative extends Model
{
    protected $connection = 'community_services';
    protected $table = 'community_services.funeral_representatives';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'household_id',
        'actor_id',
        'status',
        'evidence_reference',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'status' => RepresentativeStatus::class,
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
