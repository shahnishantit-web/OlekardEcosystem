<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Services\WatchlistScreenerService;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Models\WatchlistEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WatchlistScreenerServiceTest extends TestCase
{
    use DatabaseTransactions;

    private WatchlistScreenerService $service;
    private AuditServiceInterface $audit;
    private RiskMetricsInterface $metrics;
    private ConfigurationServiceInterface $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit   = $this->createStub(AuditServiceInterface::class);
        $this->metrics = $this->createStub(RiskMetricsInterface::class);
        $this->config  = $this->createMock(ConfigurationServiceInterface::class);

        $this->config->method('getOrDefault')
            ->willReturnCallback(function (string $key, mixed $default) {
                return match ($key) {
                    'risk.watchlist_similarity_threshold' => 0.75,
                    default                               => $default,
                };
            });

        $this->service = new WatchlistScreenerService($this->audit, $this->metrics, $this->config);
    }

    private function createEntry(string $fullName, ?array $aliases = null): WatchlistEntry
    {
        $entry = new WatchlistEntry();
        $entry->fill([
            'id'          => (string) Str::orderedUuid(),
            'list_type'   => 'sanctions',
            'entity_type' => 'individual',
            'source'      => 'test',
            'is_active'   => true,
            'aliases'     => $aliases,
        ]);
        $entry->full_name = $fullName; // triggers mutator
        $entry->save();

        return $entry;
    }

    /** Exact name match returns a WatchlistMatch with method=exact */
    public function test_exact_name_match_returns_match(): void
    {
        $this->createEntry('John Doe');

        $matches = $this->service->screen('customer', Str::uuid()->toString(), 'John Doe');

        $this->assertCount(1, $matches);
        $this->assertSame('exact', $matches[0]->match_method);
        $this->assertEqualsWithDelta(1.0, $matches[0]->match_score, 0.001);
    }

    /** Case-insensitive exact match works */
    public function test_exact_match_is_case_insensitive(): void
    {
        $this->createEntry('JOHN DOE');

        $matches = $this->service->screen('customer', Str::uuid()->toString(), 'john doe');

        $this->assertCount(1, $matches);
        $this->assertSame('exact', $matches[0]->match_method);
    }

    /** No matching entry → empty array */
    public function test_no_match_returns_empty_array(): void
    {
        $this->createEntry('Alice Smith');

        $matches = $this->service->screen('customer', Str::uuid()->toString(), 'Bob Jones');

        $this->assertEmpty($matches);
    }

    /** Alias match returns a WatchlistMatch with method=alias */
    public function test_alias_match_returns_match(): void
    {
        $this->createEntry('Robert Johnson', aliases: ['Bob Johnson', 'Bobby J']);

        $matches = $this->service->screen('customer', Str::uuid()->toString(), 'Bob Johnson');

        $this->assertCount(1, $matches);
        $this->assertSame('alias', $matches[0]->match_method);
    }

    /** Duplicate screening of same subject → returns existing match without creating duplicate */
    public function test_duplicate_screening_returns_existing_match(): void
    {
        $this->createEntry('John Doe');
        $subjectId = Str::uuid()->toString();

        $first  = $this->service->screen('customer', $subjectId, 'John Doe');
        $second = $this->service->screen('customer', $subjectId, 'John Doe');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame($first[0]->id, $second[0]->id);
    }

    /** Match creates a RiskAlert with type=watchlist */
    public function test_match_creates_watchlist_alert(): void
    {
        $this->createEntry('John Doe');

        $this->service->screen('customer', Str::uuid()->toString(), 'John Doe');

        $this->assertDatabaseHas('risk.risk_alerts', [
            'alert_type' => 'watchlist',
            'status'     => 'open',
        ], 'risk');
    }

    /** Inactive entry does not match */
    public function test_inactive_entry_does_not_match(): void
    {
        $entry = $this->createEntry('John Doe');
        $entry->update(['is_active' => false]);

        $matches = $this->service->screen('customer', Str::uuid()->toString(), 'John Doe');

        $this->assertEmpty($matches);
    }
}
