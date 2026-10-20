<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Models;

use App\Modules\MultiCurrency\Domain\Enums\FxRateStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $source
 * @property string|null $provider
 * @property Carbon|null $observed_at
 * @property string $id
 * @property string $from_currency_code
 * @property string $to_currency_code
 * @property string $rate
 * @property string $spread
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string $created_by
 * @property string|null $approved_by
 * @property string|null $change_request_id
 * @property FxRateStatus $status
 * @property Carbon $created_at
 */
final class FxRateModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'multi_currency.fx_rates';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(CurrencyModel::class, 'from_currency_code', 'code');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(CurrencyModel::class, 'to_currency_code', 'code');
    }

    protected $casts = [
        'observed_at' => 'datetime',
        'rate' => 'string',
        'spread' => 'string',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'status' => FxRateStatus::class,
        'created_at' => 'datetime',
    ];
}
