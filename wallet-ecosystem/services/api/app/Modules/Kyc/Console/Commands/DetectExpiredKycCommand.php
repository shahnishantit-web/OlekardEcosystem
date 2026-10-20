<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Console\Commands;

use App\Modules\Kyc\Application\Services\KycCaseService;
use Illuminate\Console\Command;

final class DetectExpiredKycCommand extends Command
{
    protected $signature = 'kyc:detect-expired
                            {--batch-size=100 : Number of cases to process per run}
                            {--dry-run : Report what would be expired without making any changes}';

    protected $description = 'Detect approved KYC cases past their expires_at and transition them to expired status.';

    public function handle(KycCaseService $service): int
    {
        $batchSize = (int) $this->option('batch-size');
        $dryRun    = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        $this->info('KYC expiry detection started.');

        $result = $service->detectAndExpireCases($batchSize, $dryRun);

        $this->info(sprintf(
            'Processed %d cases. Expired: %d. Skipped: %d. Errors: %d.',
            $result['expired'] + $result['skipped'] + $result['errors'],
            $result['expired'],
            $result['skipped'],
            $result['errors'],
        ));

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
