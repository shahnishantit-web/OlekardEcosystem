<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Provisions configured module schemas before Laravel creates platform.migrations. */
final class BootstrapDatabaseSchemasCommand extends Command
{
    protected $signature = 'platform:bootstrap-schemas';

    protected $description = 'Create configured PostgreSQL module schemas without creating tables or migration records.';

    public function handle(): int
    {
        $schemas = [];
        foreach ((array) config('database.connections') as $connection) {
            if (($connection['driver'] ?? null) !== 'pgsql') continue;
            $schema = $connection['search_path'] ?? null;
            if (! is_string($schema) || preg_match('/^[a-z_][a-z0-9_]*$/D', $schema) !== 1) {
                $this->error('A PostgreSQL connection has an invalid schema search_path.');
                return self::FAILURE;
            }
            $schemas[$schema] = true;
        }

        $platform = DB::connection('platform');
        foreach (array_keys($schemas) as $schema) {
            $platform->statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
        }

        $this->info('Ensured '.count($schemas).' configured PostgreSQL schemas exist.');
        $this->line('No application tables or migration history were created or changed.');
        return self::SUCCESS;
    }
}
