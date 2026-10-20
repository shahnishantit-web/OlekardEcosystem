<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $provider_code
 * @property string      $provider_reference
 * @property string|null $transaction_id
 * @property array       $payload
 * @property bool        $verified
 * @property bool        $processed
 * @property string|null $processing_error
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 */
final class ServiceProviderCallbackModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'service.service_provider_callbacks';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload'      => 'array',
        'verified'     => 'boolean',
        'processed'    => 'boolean',
        'received_at'  => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ServiceTransactionModel::class, 'transaction_id');
    }
}
