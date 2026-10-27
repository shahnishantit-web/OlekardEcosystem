<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string       $id
 * @property string       $dispute_case_id
 * @property int          $hold_amount_minor
 * @property string       $currency_code
 * @property bool         $settled_funds_receivable
 * @property string       $authorised_by
 * @property Carbon       $authorised_at
 * @property Carbon|null  $released_at
 */
final class DisputeHoldModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.dispute_holds';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false; // authorised_at / released_at only

    protected $fillable = [
        'id',
        'dispute_case_id',
        'hold_amount_minor',
        'currency_code',
        'settled_funds_receivable',
        'authorised_by',
        'authorised_at',
        'released_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hold_amount_minor'       => 'integer',
            'settled_funds_receivable' => 'boolean',
            'authorised_at'           => 'immutable_datetime',
            'released_at'             => 'immutable_datetime',
        ];
    }

    public function disputeCase(): BelongsTo
    {
        return $this->belongsTo(PaymentDisputeCaseModel::class, 'dispute_case_id');
    }
}
