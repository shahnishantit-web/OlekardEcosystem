<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string       $id
 * @property string       $account_id
 * @property string|null  $config_version_id
 * @property string       $operation_type
 * @property string       $base_url
 * @property string       $endpoint_path
 * @property string       $http_method
 * @property string       $content_type
 * @property array<string, string> $non_secret_headers
 * @property string       $auth_profile
 * @property array<string, string> $secret_key_references  role => key_name (never raw values)
 * @property array<int, array<string, mixed>> $request_field_mappings
 * @property array<int, array<string, mixed>> $response_field_mappings
 * @property string|null  $provider_txn_ref_mapping
 * @property string|null  $idempotency_ref_mapping
 * @property string       $amount_format
 * @property string       $currency_format
 * @property array<string> $success_status_values
 * @property array<string> $pending_status_values
 * @property array<string> $failure_status_values
 * @property int          $timeout_ms
 * @property int          $max_retries
 * @property int          $retry_backoff_ms
 * @property string|null  $callback_verification_profile
 * @property string|null  $api_version
 * @property bool         $is_payout
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class ApiOperationConfigModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.api_operation_configs';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'config_version_id',
        'operation_type',
        'base_url',
        'endpoint_path',
        'http_method',
        'content_type',
        'non_secret_headers',
        'auth_profile',
        'secret_key_references',
        'request_field_mappings',
        'response_field_mappings',
        'provider_txn_ref_mapping',
        'idempotency_ref_mapping',
        'amount_format',
        'currency_format',
        'success_status_values',
        'pending_status_values',
        'failure_status_values',
        'timeout_ms',
        'max_retries',
        'retry_backoff_ms',
        'callback_verification_profile',
        'api_version',
        'is_payout',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'non_secret_headers'      => 'array',
            'secret_key_references'   => 'array',
            'request_field_mappings'  => 'array',
            'response_field_mappings' => 'array',
            'success_status_values'   => 'array',
            'pending_status_values'   => 'array',
            'failure_status_values'   => 'array',
            'timeout_ms'              => 'integer',
            'max_retries'             => 'integer',
            'retry_backoff_ms'        => 'integer',
            'is_payout'               => 'boolean',
            'created_at'              => 'immutable_datetime',
            'updated_at'              => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccountModel::class, 'account_id');
    }

    public function configVersion(): BelongsTo
    {
        return $this->belongsTo(ConfigurationVersionModel::class, 'config_version_id');
    }

    /**
     * FR-014: secret_key_references must NEVER be included in serialised output.
     * Override toArray() to mask the references.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        if (isset($data['secret_key_references'])) {
            $masked = [];

            foreach ((array) $data['secret_key_references'] as $role => $keyName) {
                $masked[$role] = ['key' => $keyName, 'masked' => '****'];
            }

            $data['secret_key_references'] = $masked;
        }

        return $data;
    }
}
