<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class LedgerIntegritySnapshotModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.ledger_integrity_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'scan_at',
        'wallets_checked',
        'discrepancy_count',
        'affected_wallet_ids',
        'outcome',
        'duration_ms',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'affected_wallet_ids' => 'array',
        'scan_at'             => 'datetime',
        'created_at'          => 'datetime',
    ];
}
