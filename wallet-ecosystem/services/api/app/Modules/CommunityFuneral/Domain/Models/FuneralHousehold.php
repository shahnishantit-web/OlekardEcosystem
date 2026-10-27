<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Models;

use App\Modules\CommunityFuneral\Domain\Enums\HouseholdStatus;
use Illuminate\Database\Eloquent\Model;

final class FuneralHousehold extends Model
{
    protected $connection = 'community_services';
    protected $table = 'community_services.funeral_households';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'community_id',
        'private_reference',
        'status',
        'exemption_reference',
        'created_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'status' => HouseholdStatus::class,
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
