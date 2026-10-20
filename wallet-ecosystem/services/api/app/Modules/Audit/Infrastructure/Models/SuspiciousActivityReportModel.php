<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * @property string $id
 * @property string $report_number
 * @property string $subject_user_id
 * @property string $reason_code
 * @property string $reason_narrative
 * @property string $status
 * @property array  $flagged_transaction_ids
 * @property array  $subject_identity_snapshot
 * @property array  $aml_alert_history
 * @property array  $risk_score_timeline
 * @property string $created_by_actor_id
 * @property string $idempotency_key
 */
class SuspiciousActivityReportModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'audit.suspicious_activity_reports';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'flagged_transaction_ids'  => 'array',
        'subject_identity_snapshot' => 'array',
        'aml_alert_history'        => 'array',
        'risk_score_timeline'      => 'array',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Uuid::uuid7();
            }
            if (empty($model->report_number)) {
                $model->report_number = static::generateReportNumber();
            }
        });
    }

    protected static function generateReportNumber(): string
    {
        $date = now()->format('Ymd');
        // Atomic sequence: count existing SARs for today and pad to 6 digits
        $count = DB::connection('platform')
            ->table('audit.suspicious_activity_reports')
            ->whereRaw("report_number LIKE ?", ['SAR-'.$date.'-%'])
            ->count();

        return sprintf('SAR-%s-%06d', $date, $count + 1);
    }
}
