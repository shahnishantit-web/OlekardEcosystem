<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class BankProviderDocumentationGapModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.bank_provider_documentation_gaps';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'provider_code',
        'bank_display_name',
        'required_items',
        'received_items',
        'status',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'required_items' => 'array',
            'received_items' => 'array',
            'created_at'     => 'immutable_datetime',
            'updated_at'     => 'immutable_datetime',
        ];
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }
}
