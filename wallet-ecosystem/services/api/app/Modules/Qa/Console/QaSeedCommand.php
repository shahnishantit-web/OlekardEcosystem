<?php

declare(strict_types=1);

namespace App\Modules\Qa\Console;

use App\Modules\Qa\Infrastructure\Seeders\TestCaseSeeder;
use Illuminate\Console\Command;

final class QaSeedCommand extends Command
{
    protected $signature = 'qa:seed
                            {--dry-run : Show what would be seeded without writing to DB}';

    protected $description = 'Seed QA test cases (18 categories × 8 scenario types = 144 records). Idempotent upsert.';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry-run mode: would seed 144 test cases (18 categories × 8 scenario types).');
            $this->info('Categories: unit_auth, integration_auth, api_authorization, wallet_balance,');
            $this->info('            ledger_balance, transfer, duplicate_transaction, callback,');
            $this->info('            payment_failure, reconciliation, kyc, agent, security,');
            $this->info('            performance, load, disaster_recovery, uat, regression');
            $this->info('Scenario types: happy_path, validation, authorization, boundary,');
            $this->info('                failure, idempotency, audit, regression');
            return self::SUCCESS;
        }

        $this->call(TestCaseSeeder::class);
        $this->info('QA test cases seeded successfully.');
        return self::SUCCESS;
    }
}
