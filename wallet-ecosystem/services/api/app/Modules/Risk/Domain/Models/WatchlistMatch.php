<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchlistMatch extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.watchlist_matches';

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'match_score' => 'float',
        'screened_at' => 'datetime',
        'cleared_at'  => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WatchlistEntry::class, 'watchlist_entry_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(RiskAlert::class, 'alert_id');
    }
}
