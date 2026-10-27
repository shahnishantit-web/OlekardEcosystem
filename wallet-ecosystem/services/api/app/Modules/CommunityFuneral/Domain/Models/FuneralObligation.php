<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Models;

use App\Modules\CommunityFuneral\Domain\Enums\FulfilmentStatus;
use App\Modules\CommunityFuneral\Domain\Enums\ObligationStatus;
use Illuminate\Database\Eloquent\Model;

final class FuneralObligation extends Model
{
    protected $connection = 'community_services';
    protected $table = 'community_services.funeral_obligations';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'collection_id',
        'snapshot_household_id',
        'household_id',
        'amount_minor',
        'currency_code',
        'status',
        'fulfilment_status',
        'payment_intent_id',
        'fulfilled_at',
    ];

    protected $casts = [
        'status' => ObligationStatus::class,
        'fulfilment_status' => FulfilmentStatus::class,
        'amount_minor' => 'integer',
        'fulfilled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
