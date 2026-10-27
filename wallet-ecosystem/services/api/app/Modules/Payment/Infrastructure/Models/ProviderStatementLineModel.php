<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Payment\Domain\Enums\StatementLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                    $id
 * @property string                    $statement_id
 * @property StatementLineType         $line_type
 * @property string                    $provider_reference_id
 * @property int                       $amount_minor
 * @property string                    $currency_code
 * @property string                    $direction
 * @property \Illuminate\Support\Carbon $settled_at
 * @property StatementLineMatchStatus  $match_status
 * @property string|null               $matched_entity_type
 * @property string|null               $matched_entity_id
 * @property string|null               $clearing_record_id
 * @property string|null               $exception_id
 * @property array<string, mixed>|null $metadata
 */
final class ProviderStatementLineModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.provider_statement_lines';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'statement_id',
        'line_type',
        'provider_reference_id',
        'amount_minor',
        'currency_code',
        'direction',
        'settled_at',
        'match_status',
        'matched_entity_type',
        'matched_entity_id',
        'clearing_record_id',
        'exception_id',
        'metadata',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'line_type'    => StatementLineType::class,
            'match_status' => StatementLineMatchStatus::class,
            'amount_minor' => 'integer',
            'settled_at'   => 'immutable_datetime',
            'metadata'     => 'array',
            'created_at'   => 'immutable_datetime',
            'updated_at'   => 'immutable_datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(ProviderStatementModel::class, 'statement_id');
    }
}
