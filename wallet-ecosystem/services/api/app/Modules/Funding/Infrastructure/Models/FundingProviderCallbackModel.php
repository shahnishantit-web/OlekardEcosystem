<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string       $id
 * @property string|null  $funding_transaction_id
 * @property string       $provider
 * @property string       $provider_reference_id
 * @property string       $status
 * @property array        $raw_payload
 * @property bool         $signature_valid
 * @property Carbon       $received_at
 * @property Carbon|null  $processed_at
 * @property Carbon       $created_at
 * @property Carbon       $updated_at
 */
class FundingProviderCallbackModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'funding.funding_provider_callbacks';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'raw_payload'   => 'array',
        'signature_valid' => 'boolean',
        'received_at'   => 'datetime',
        'processed_at'  => 'datetime',
    ];
}
