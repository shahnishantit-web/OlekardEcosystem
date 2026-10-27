<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payment\Domain\Contracts\PaymentIdempotencyRepositoryInterface;
use Illuminate\Console\Command;

final class PrunePaymentIdempotencyRecordsCommand extends Command
{
    protected $signature = 'payment:prune-idempotency-records';

    protected $description = 'Delete expired payment idempotency records (expires_at < NOW())';

    public function __construct(
        private readonly PaymentIdempotencyRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $deleted = $this->repository->pruneExpired();
        $this->info("Pruned {$deleted} expired payment idempotency records.");

        return self::SUCCESS;
    }
}
