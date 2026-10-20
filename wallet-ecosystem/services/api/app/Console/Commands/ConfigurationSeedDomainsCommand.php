<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ConfigurationSeedDomainsCommand extends Command
{
    protected $signature   = 'configuration:seed-domains';

    protected $description = 'Backfill the domain column on platform.configurations from config_key prefix patterns.';

    public function handle(): int
    {
        $affected = DB::connection('platform')->statement("
            UPDATE platform.configurations
            SET domain = CASE
                WHEN config_key LIKE 'financial_policy.%' THEN 'financial_policy'
                WHEN config_key LIKE 'geographic.%'       THEN 'geographic'
                ELSE 'operational_policy'
            END
            WHERE domain IS NULL OR domain = ''
        ");

        $this->info('Domain backfill complete.');

        return self::SUCCESS;
    }
}
