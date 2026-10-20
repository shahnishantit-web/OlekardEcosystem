<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $bank_notification_id
 * @property int         $amount
 * @property string      $currency_code
 * @property string|null $raw_reference
 * @property string|null $narration
 * @property Carbon      $received_at
 * @property string      $resolution_status
 * @property string|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property array       $raw_payload
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class UnmatchedDepositModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'funding.unmatched_deposits';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'raw_payload'  => 'array',
        'received_at'  => 'datetime',
        'resolved_at'  => 'datetime',
    ];
}
