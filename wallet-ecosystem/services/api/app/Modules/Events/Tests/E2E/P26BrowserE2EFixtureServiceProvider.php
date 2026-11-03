<?php

declare(strict_types=1);

namespace App\Modules\Events\Tests\E2E;

use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Command\Command;

/** Test-only fixture binding; never registered outside the guarded P26 E2E environment. */
final class P26BrowserE2EFixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $database = (string) config('database.connections.platform.database');
        if (! $this->app->environment('testing')
            || preg_match('/\Awallet_ecosystem_p26_e2e_[0-9]{8}_[a-f0-9]{8}\z/', $database) !== 1) {
            throw new \RuntimeException('P26 fixture approvals require a dedicated dated P26 E2E database.');
        }

        $eventId = (string) env('P26_E2E_EVENT_ID', '');
        $organisationId = (string) env('P26_E2E_ORGANISATION_ID', '');
        $categoryIds = array_values(array_filter(array_map(
            'trim', explode(',', (string) env('P26_E2E_CATEGORY_IDS', '')),
        )));
        if ($eventId === '' || $organisationId === '' || $categoryIds === []) {
            throw new \RuntimeException('P26 fixture approval scope is incomplete.');
        }

        $scopes = [$eventId => ['organisation_id' => $organisationId, 'category_ids' => $categoryIds]];
        $extraEventId = (string) env('P26_E2E_EXTRA_EVENT_ID', '');
        $extraCategoryIds = array_values(array_filter(array_map(
            'trim', explode(',', (string) env('P26_E2E_EXTRA_CATEGORY_IDS', '')),
        )));
        if (($extraEventId === '') !== ($extraCategoryIds === [])) {
            throw new \RuntimeException('P26 additional fixture approval scope is incomplete.');
        }
        if ($extraEventId !== '') {
            $scopes[$extraEventId] = ['organisation_id' => $organisationId, 'category_ids' => $extraCategoryIds];
        }

        $this->app->bind(ComplimentaryAllocationApprovalContract::class, static fn () => new P26BrowserE2EComplimentaryApproval($scopes));
    }

    public function boot(): void
    {
        Artisan::command('p26:e2e:assert-database', function (): int {
            $expected = (string) config('database.connections.platform.database');
            $expectedHost = (string) env('DB_HOST', '127.0.0.1');
            $expectedPort = (string) env('DB_PORT', '5432');
            if (! app()->environment('testing')
                || preg_match('/\Awallet_ecosystem_p26_e2e_[0-9]{8}_[a-f0-9]{8}\z/', $expected) !== 1) {
                throw new \RuntimeException('P26 database preflight requires a dedicated dated test database.');
            }

            foreach (['platform', 'identity', 'community_services', 'notification', 'payment', 'ledger'] as $name) {
                if (config("database.connections.{$name}.database") !== $expected
                    || config("database.connections.{$name}.host") !== $expectedHost
                    || (string) config("database.connections.{$name}.port") !== $expectedPort) {
                    throw new \RuntimeException("P26 {$name} connection is not configured for its isolated target.");
                }
                $row = DB::connection($name)->selectOne('SELECT current_database() AS database_name');
                if (! is_object($row) || ($row->database_name ?? null) !== $expected) {
                    throw new \RuntimeException("P26 {$name} effective connection does not match its isolated target.");
                }
            }

            $this->line("P26 effective database verified: {$expected} via {$expectedHost}:{$expectedPort}.");

            return Command::SUCCESS;
        })->purpose('Verify the effective database is the unique isolated P26 E2E target.');
    }
}
