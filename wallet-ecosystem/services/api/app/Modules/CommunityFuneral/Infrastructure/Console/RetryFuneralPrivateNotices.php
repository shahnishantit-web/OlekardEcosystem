<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Infrastructure\Console;

use App\Modules\CommunityFuneral\Application\Jobs\DispatchFuneralPrivateNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RetryFuneralPrivateNotices extends Command
{
    protected $signature = 'funeral:notices-retry {--limit=100 : Maximum pending notices to enqueue}';
    protected $description = 'Requeue pending private Funeral Assistance inbox notices';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $ids = DB::connection('community_services')->table('community_services.funeral_private_notices')
            ->where('status', 'pending')->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            DispatchFuneralPrivateNotice::dispatch((string) $id);
        }
        $this->info('Queued '.$ids->count().' pending private notices.');

        return self::SUCCESS;
    }
}
