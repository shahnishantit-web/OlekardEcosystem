<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WalletStatusHistoryModel extends Model
{
    protected $connection = 'wallet';

    protected $table = 'wallet.wallet_status_history';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    /** Append-only: prevent updates to existing history records. */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('WalletStatusHistoryModel is append-only. Updates are not permitted.');
        }

        return parent::save($options);
    }

    public function delete(): bool|null
    {
        throw new LogicException('WalletStatusHistoryModel is append-only. Deletions are not permitted.');
    }
}
