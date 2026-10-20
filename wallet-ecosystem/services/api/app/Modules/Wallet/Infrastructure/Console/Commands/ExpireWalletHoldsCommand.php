<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Console\Commands;

use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use App\Modules\Wallet\Domain\Events\WalletHoldExpiredEvent;
use App\Modules\Wallet\Infrastructure\Models\WalletHoldModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class ExpireWalletHoldsCommand extends Command
{
    protected $signature = 'wallet:expire-holds
        {--batch-size=100 : Number of holds to process per batch}
        {--dry-run : Report what would be expired without making changes}';

    protected $description = 'Expire active wallet holds whose expiry timestamp has passed.';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $dryRun    = (bool) $this->option('dry-run');
        $expired   = 0;

        if ($dryRun) {
            $this->info('Dry run mode — no changes will be made.');
        }

        do {
            $batch = WalletHoldModel::on('wallet')
                ->where('status', WalletHoldStatus::Active)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->limit($batchSize)
                ->get();

            foreach ($batch as $hold) {
                if ($dryRun) {
                    $this->line("Would expire hold {$hold->id} on pocket {$hold->pocket_id}.");
                    $expired++;
                    continue;
                }

                try {
                    DB::connection('wallet')->transaction(function () use ($hold): void {
                        $locked = WalletHoldModel::on('wallet')->lockForUpdate()->find($hold->id);
                        if ($locked === null || $locked->status !== WalletHoldStatus::Active) {
                            return; // Already processed by another process
                        }

                        $locked->status     = WalletHoldStatus::Expired;
                        $locked->expired_at = now();
                        $locked->save();

                        $pocket = \App\Modules\Wallet\Infrastructure\Models\CurrencyPocketModel::on('wallet')
                            ->select('wallet_id')
                            ->where('id', $locked->pocket_id)
                            ->first();

                        $correlationId = (string) Uuid::uuid7();
                        event(new WalletHoldExpiredEvent(
                            $pocket?->wallet_id ?? $locked->pocket_id,
                            $locked->id,
                            $locked->pocket_id,
                            $locked->amount,
                            $locked->expired_at->toIso8601String(),
                            $correlationId,
                        ));
                    });

                    $expired++;
                } catch (\Throwable $e) {
                    Log::error('wallet.expire-holds.error', [
                        'hold_id' => $hold->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } while ($batch->count() === $batchSize);

        $this->info("Expired {$expired} hold(s).");

        return self::SUCCESS;
    }
}
