<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class FuneralClosure extends Model
{
    protected $connection = 'community_services';
    protected $table = 'community_services.funeral_closures';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'collection_id',
        'closed_by',
        'closed_at',
        'confirmed_gross_minor',
        'fee_total_minor',
        'confirmed_refunds_minor',
        'net_support_minor',
        'late_review_gross_minor',
        'unresolved_liability_count',
        'settlement_status',
        'payout_status',
        'carry_forward_reference',
        'carry_forward_requested_by',
        'carry_forward_requested_at',
        'carry_forward_approved_by',
        'carry_forward_approved_at',
        'archived_at',
    ];

    protected $casts = [
        'confirmed_gross_minor' => 'integer',
        'fee_total_minor' => 'integer',
        'confirmed_refunds_minor' => 'integer',
        'net_support_minor' => 'integer',
        'late_review_gross_minor' => 'integer',
        'unresolved_liability_count' => 'integer',
        'closed_at' => 'datetime',
        'carry_forward_requested_at' => 'datetime',
        'carry_forward_approved_at' => 'datetime',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
