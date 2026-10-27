<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\ProviderStatementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                 $id
 * @property string                 $provider_code
 * @property string                 $account_id
 * @property string                 $environment
 * @property string                 $currency_code
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property string                 $source_hash
 * @property ProviderStatementStatus $status
 * @property int                    $total_lines
 * @property int                    $matched_lines
 * @property int                    $exception_lines
 * @property string|null            $submitted_by
 * @property \Illuminate\Support\Carbon $ingested_at
 * @property \Illuminate\Support\Carbon|null $matching_completed_at
 */
final class ProviderStatementModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.provider_statements';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'provider_code',
        'account_id',
        'environment',
        'currency_code',
        'period_start',
        'period_end',
        'source_hash',
        'status',
        'total_lines',
        'matched_lines',
        'exception_lines',
        'submitted_by',
        'ingested_at',
        'matching_completed_at',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'                => ProviderStatementStatus::class,
            'total_lines'           => 'integer',
            'matched_lines'         => 'integer',
            'exception_lines'       => 'integer',
            'period_start'          => 'immutable_datetime',
            'period_end'            => 'immutable_datetime',
            'ingested_at'           => 'immutable_datetime',
            'matching_completed_at' => 'immutable_datetime',
            'created_at'            => 'immutable_datetime',
            'updated_at'            => 'immutable_datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProviderStatementLineModel::class, 'statement_id');
    }
}
