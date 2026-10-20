<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use App\Modules\Platform\Domain\Contracts\CurrencyContract;
use Illuminate\Database\Eloquent\Model;

class CurrencyModel extends Model implements CurrencyContract
{
    protected $connection = 'wallet';

    protected $table = 'wallet.currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_primary' => 'boolean',
        'is_tbd'     => 'boolean',
        'precision'  => 'integer',
    ];

    // ── CurrencyContract ─────────────────────────────────────────────────────

    public function code(): string
    {
        return (string) $this->getAttribute('code');
    }

    public function precision(): int
    {
        return (int) $this->getAttribute('precision');
    }

    public function name(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function isSupported(): bool
    {
        return (bool) $this->getAttribute('is_enabled');
    }

    public function toCurrencyContract(): CurrencyContract
    {
        return $this;
    }
}
