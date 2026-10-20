<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform\Reliability;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * E27 SC-001: Verifies zero uncovered financial routes after T010–T016 have been applied.
 */
final class IdempotencyCoverageCommandTest extends TestCase
{
    public function test_all_financial_routes_have_idempotency_coverage(): void
    {
        $exitCode = Artisan::call('reliability:audit-idempotency-coverage');

        $this->assertSame(
            0,
            $exitCode,
            "Financial routes are missing idempotency middleware.\n"
            . Artisan::output()
        );
    }
}
