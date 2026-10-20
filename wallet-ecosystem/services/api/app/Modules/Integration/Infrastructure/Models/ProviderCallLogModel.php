<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Models;

use App\Modules\Integration\Domain\Enums\ProviderCallOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string              $id
 * @property string              $integration_id
 * @property string|null         $correlation_id
 * @property string|null         $provider_reference
 * @property array|null          $request_summary
 * @property int|null            $response_code
 * @property ProviderCallOutcome $outcome
 * @property bool                $retry_eligible
 * @property int|null            $elapsed_ms
 * @property \Carbon\CarbonInterface      $created_at
 */
final class ProviderCallLogModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'integration.provider_call_logs';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'outcome'         => ProviderCallOutcome::class,
        'retry_eligible'  => 'boolean',
        'request_summary' => 'array',
        'created_at'      => 'datetime',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(IntegrationModel::class, 'integration_id');
    }
}
