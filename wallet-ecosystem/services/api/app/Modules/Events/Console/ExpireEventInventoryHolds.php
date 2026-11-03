<?php

declare(strict_types=1);

namespace App\Modules\Events\Console;

use App\Modules\Events\Application\EventInventoryHoldService;
use Illuminate\Console\Command;

final class ExpireEventInventoryHolds extends Command
{
    protected $signature = 'events:expire-inventory-holds';

    protected $description = 'Mark expired P25 inventory holds as released from capacity.';

    public function handle(EventInventoryHoldService $holds): int
    {
        $count = $holds->expireDueHolds();
        $this->info("Expired {$count} event inventory hold(s).");

        return self::SUCCESS;
    }
}
