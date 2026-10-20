<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Models;

use App\Modules\Qr\Domain\Enums\QrCodeStatus;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string               $id
 * @property string               $merchant_id
 * @property QrCodeType           $type
 * @property string|null          $label
 * @property int|null             $amount
 * @property string               $currency
 * @property string|null          $merchant_ref
 * @property string               $nonce
 * @property string               $payload
 * @property QrCodeStatus         $status
 * @property string|null          $reserved_by_customer_id
 * @property \Carbon\Carbon|null  $reserved_until
 * @property \Carbon\Carbon|null  $expires_at
 * @property \Carbon\Carbon|null  $created_at
 * @property \Carbon\Carbon|null  $updated_at
 */
final class QrCodeModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'qr.qr_codes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'type'          => QrCodeType::class,
        'status'        => QrCodeStatus::class,
        'amount'        => 'integer',
        'expires_at'    => 'datetime',
        'reserved_until' => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(QrPaymentTransactionModel::class, 'qr_code_id');
    }
}
