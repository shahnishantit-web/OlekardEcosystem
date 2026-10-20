<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\Enums\JournalEntryType;
use App\Modules\Ledger\Domain\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property \App\Modules\Ledger\Domain\Enums\JournalEntryType $entry_type
 * @property \App\Modules\Ledger\Domain\Enums\JournalStatus $status
 * @property string $transaction_reference
 * @property string $correlation_id
 * @property string $currency_code
 * @property \Illuminate\Support\Carbon|null $posted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Ledger\Infrastructure\Models\JournalLineModel> $lines
 */
class JournalModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'ledger.journals';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    // No updated_at — immutability signal
    const UPDATED_AT = null;

    protected $casts = [
        'entry_type' => JournalEntryType::class,
        'status'     => JournalStatus::class,
        'posted_at'  => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLineModel::class, 'journal_id');
    }
}
