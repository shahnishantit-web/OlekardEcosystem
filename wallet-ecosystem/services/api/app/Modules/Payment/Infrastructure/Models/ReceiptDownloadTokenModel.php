<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\ReceiptDownloadTokenStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                                      $id
 * @property string                                      $payment_intent_id
 * @property string                                      $requestor_id
 * @property string                                      $token
 * @property \Illuminate\Support\Carbon|null             $used_at
 * @property \Illuminate\Support\Carbon                  $expires_at
 * @property \Illuminate\Support\Carbon                  $created_at
 * @property \Illuminate\Support\Carbon                  $updated_at
 */
final class ReceiptDownloadTokenModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.receipt_download_tokens';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payment_intent_id',
        'requestor_id',
        'token',
        'used_at',
        'expires_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at'    => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function status(): ReceiptDownloadTokenStatus
    {
        if ($this->isUsed()) {
            return ReceiptDownloadTokenStatus::Used;
        }

        if ($this->isExpired()) {
            return ReceiptDownloadTokenStatus::Expired;
        }

        return ReceiptDownloadTokenStatus::Pending;
    }
}
