<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\Enums\JournalLineDirection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $journal_id
 * @property string $ledger_account_id
 * @property \App\Modules\Ledger\Domain\Enums\JournalLineDirection $direction
 * @property int $amount
 * @property string $currency_code
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class JournalLineModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'ledger.journal_lines';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    // No updated_at — append-only signal
    const UPDATED_AT = null;

    protected $casts = [
        'direction' => JournalLineDirection::class,
    ];

    /** @throws \LogicException if the record already exists (append-only invariant) */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('JournalLine records are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    /** @throws \LogicException always — journal lines are immutable */
    public function delete(): bool|null
    {
        throw new \LogicException('JournalLine records are immutable and cannot be deleted.');
    }
}
