<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentExportRequestStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                                      $id
 * @property string                                      $requestor_id
 * @property string                                      $scope_type
 * @property string|null                                 $organisation_id
 * @property array<string, mixed>                        $filters
 * @property PaymentExportRequestStatus                  $status
 * @property string|null                                 $token
 * @property int|null                                    $row_count
 * @property \Illuminate\Support\Carbon|null             $generated_at
 * @property \Illuminate\Support\Carbon|null             $token_expires_at
 * @property \Illuminate\Support\Carbon|null             $downloaded_at
 * @property string|null                                 $failure_reason
 * @property \Illuminate\Support\Carbon                  $created_at
 * @property \Illuminate\Support\Carbon                  $updated_at
 */
final class PaymentExportRequestModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_export_requests';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'requestor_id',
        'scope_type',
        'organisation_id',
        'filters',
        'status',
        'token',
        'row_count',
        'generated_at',
        'token_expires_at',
        'downloaded_at',
        'failure_reason',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'filters'          => 'array',
            'status'           => PaymentExportRequestStatus::class,
            'generated_at'     => 'immutable_datetime',
            'token_expires_at' => 'immutable_datetime',
            'downloaded_at'    => 'immutable_datetime',
            'created_at'       => 'immutable_datetime',
            'updated_at'       => 'immutable_datetime',
        ];
    }
}
