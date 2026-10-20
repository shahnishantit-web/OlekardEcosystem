<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use App\Modules\Platform\Domain\Enums\ReconciliationState;
use App\Modules\Platform\Domain\ValueObjects\Providers\ExternalReferenceMapping;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $platform_entity_id
 * @property string $platform_entity_type
 * @property string $provider_key
 * @property string|null $provider_reference
 * @property \Illuminate\Support\Carbon|null $reference_confirmed_at
 * @property string $operation_type
 * @property ReconciliationState|string $reconciliation_state
 * @property string|null $reconciliation_reason
 * @property string|null $reconciliation_owner
 * @property \Illuminate\Support\Carbon|null $reconciled_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ProviderExternalReferenceModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.provider_external_references';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Immutable fields — protected against mass-assignment mutations */
    protected $guarded = ['provider_reference', 'reference_confirmed_at'];

    protected $fillable = [
        'id',
        'platform_entity_id',
        'platform_entity_type',
        'provider_key',
        'operation_type',
        'reconciliation_state',
        'reconciliation_reason',
        'reconciliation_owner',
        'reconciled_at',
        'created_at',
    ];

    protected $casts = [
        'reconciliation_state'   => ReconciliationState::class,
        'reference_confirmed_at' => 'immutable_datetime',
        'reconciled_at'          => 'immutable_datetime',
        'created_at'             => 'immutable_datetime',
    ];

    public function toEntity(): ExternalReferenceMapping
    {
        return ExternalReferenceMapping::fromArray($this->toArray());
    }
}
