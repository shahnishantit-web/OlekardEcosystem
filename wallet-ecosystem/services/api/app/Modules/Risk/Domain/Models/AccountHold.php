<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use App\Modules\Risk\Domain\Enums\HoldStatus;
use App\Modules\Risk\Domain\Enums\HoldType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountHold extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.account_holds';

    protected $guarded = [];

    protected $casts = [
        'hold_type'  => HoldType::class,
        'status'     => HoldStatus::class,
        'placed_at'  => 'datetime',
        'expires_at' => 'datetime',
        'lifted_at'  => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', HoldStatus::Active->value);
    }
}
