<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string       $id
 * @property string       $account_id
 * @property string|null  $config_version_id
 * @property string       $evidence_type
 * @property string       $title
 * @property string|null  $notes
 * @property string|null  $file_reference
 * @property array<string, mixed>|null $test_run_result
 * @property string       $submitted_by_actor_id
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class CertificationEvidenceModel extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'payment';
    protected $table      = 'payment.certification_evidence';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'config_version_id',
        'evidence_type',
        'title',
        'notes',
        'file_reference',
        'test_run_result',
        'submitted_by_actor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'test_run_result' => 'array',
            'created_at'      => 'immutable_datetime',
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
}
