<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Funding;

use App\Modules\Funding\Application\Services\FundingInitiationService;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Exceptions\FundingLimitExceededException;
use Tests\Feature\Modules\Funding\FundingTestCase;
use Tests\Support\ConcurrencyTestHelper;

/**
 * CC-E08-003: Concurrent initiations that would cumulatively exceed the daily limit
 * must be serialized — only one may succeed if they push the total over the cap.
 */
final class DuplicateFundingInitiationTest extends FundingTestCase
{
    public function test_concurrent_initiations_do_not_exceed_daily_limit(): void
    {
        // daily_max defaults to 2,000,000; send two requests of 1,100,000 each
        // total = 2,200,000 > 2,000,000 — exactly one must fail
        $results = ConcurrencyTestHelper::runConcurrent(2, function (): ?string {
            try {
                $service = app(FundingInitiationService::class);
                $tx      = $service->initiate([
                    'customer_id'    => $this->customerId,
                    'wallet_id'      => $this->walletId,
                    'gross_amount'   => 1_100_000,
                    'currency_code'  => 'BWP',
                    'funding_method' => FundingMethod::MobileMoney,
                    'correlation_id' => (string) \Ramsey\Uuid\Uuid::uuid7(),
                    'ip_address'     => '127.0.0.1',
                ]);
                return $tx->id;
            } catch (FundingLimitExceededException) {
                return null;
            }
        });

        $successes = array_filter($results, fn ($r) => $r !== null);
        $failures  = array_filter($results, fn ($r) => $r === null);

        $this->assertNotEmpty($successes, 'At least one initiation should succeed');
        $this->assertNotEmpty($failures,  'Concurrent limit race should block at least one');
    }
}
