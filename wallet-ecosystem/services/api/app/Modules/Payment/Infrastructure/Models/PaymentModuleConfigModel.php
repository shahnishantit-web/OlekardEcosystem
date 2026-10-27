<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $module_key
 * @property string      $display_name
 * @property bool        $is_enabled
 * @property int         $display_order
 * @property string|null $description
 * @property string|null $updated_by_actor_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PaymentModuleConfigModel extends Model
{
    use HasUuids;

    protected $connection = 'payment';
    protected $table      = 'payment.payment_module_configs';

    protected $fillable = [
        'id',
        'module_key',
        'display_name',
        'is_enabled',
        'display_order',
        'description',
        'updated_by_actor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled'    => 'boolean',
            'display_order' => 'integer',
            'created_at'    => 'immutable_datetime',
            'updated_at'    => 'immutable_datetime',
        ];
    }
}
