<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

final class ConfigurationValueModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.configuration_values';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'config_key',
        'config_value',
        'config_type',
        'value_type',
        'status',
        'version',
        'is_tbd',
        'description',
        'effective_from',
        'effective_until',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'is_tbd'         => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function getTypedValue(): mixed
    {
        if ($this->value_type === 'json') {
            return json_decode($this->config_value, true);
        }

        return $this->config_value;
    }
}
