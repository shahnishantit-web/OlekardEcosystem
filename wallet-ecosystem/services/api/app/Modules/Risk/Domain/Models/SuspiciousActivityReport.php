<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use App\Modules\Risk\Domain\Enums\SarStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspiciousActivityReport extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.suspicious_activity_reports';

    protected $guarded = [];

    protected $casts = [
        'status'                  => SarStatus::class,
        'suspected_offences'      => 'array',
        'transaction_references'  => 'array',
        'amount_involved'         => 'decimal:4',
        'approved_at'             => 'datetime',
        'submitted_at'            => 'datetime',
        'fia_acknowledged_at'     => 'datetime',
        'submission_attempts'     => 'integer',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(RiskAlert::class, 'alert_id');
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(RiskDecision::class, 'decision_id');
    }
}
