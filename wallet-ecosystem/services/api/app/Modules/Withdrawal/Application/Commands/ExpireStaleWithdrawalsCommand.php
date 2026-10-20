<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Application\Commands;

use App\Modules\Withdrawal\Application\Services\WithdrawalExpiryService;
use Illuminate\Console\Command;

final class ExpireStaleWithdrawalsCommand extends Command
{
    protected $signature = 'withdrawal:expire-stale {--dry-run : Report stale count without transitioning}';

    protected $description = 'Expire withdrawal transactions that have been in processing beyond their hold TTL';

    public function handle(WithdrawalExpiryService $expiryService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $count = $expiryService->expireStale(dryRun: true);
            $this->info("[DRY-RUN] {$count} stale withdrawal transaction(s) would be expired.");
            return self::SUCCESS;
        }

        $count = $expiryService->expireStale(dryRun: false);
        $this->info("Expired {$count} stale withdrawal transaction(s).");

        return self::SUCCESS;
    }
}
