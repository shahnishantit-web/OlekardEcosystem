<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Models;

use App\Modules\CommunityFuneral\Domain\Enums\CollectionStatus;
use Illuminate\Database\Eloquent\Model;

final class FuneralCollection extends Model
{
    protected $connection = 'community_services';
    protected $table = 'community_services.funeral_collections';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'community_id',
        'status',
        'beneficiary_id',
        'evidence_reference',
        'amount_minor',
        'currency_code',
        'terms_version',
        'snapshot_id',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'status' => CollectionStatus::class,
        'amount_minor' => 'integer',
        'reviewed_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
