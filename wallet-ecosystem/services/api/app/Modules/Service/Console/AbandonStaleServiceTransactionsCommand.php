<?php

declare(strict_types=1);

namespace App\Modules\Service\Console;

use App\Modules\Service\Application\Jobs\AbandonStaleTransactionsJob;
use Illuminate\Console\Command;

final class AbandonStaleServiceTransactionsCommand extends Command
{
    protected $signature   = 'service:abandon-stale-transactions';
    protected $description = 'Transition stale initiated service transactions to abandoned status';

    public function handle(): int
    {
        $this->info('Dispatching AbandonStaleTransactionsJob...');
        AbandonStaleTransactionsJob::dispatch();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
