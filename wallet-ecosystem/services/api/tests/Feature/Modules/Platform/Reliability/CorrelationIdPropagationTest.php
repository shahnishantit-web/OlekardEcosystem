<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform\Reliability;

use App\Modules\Funding\Application\Jobs\FundingInitiationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * E27 — FR-018, FR-019, US5: Correlation ID propagation.
 *
 * Tests:
 * 1. X-Correlation-ID response header is present on API responses
 * 2. FundingInitiationJob injects correlation_id into log context
 */
final class CorrelationIdPropagationTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = [null, 'platform'];

    // ── Test 1: API responses carry X-Correlation-ID header ───────────────────

    public function test_api_responses_include_x_correlation_id_header(): void
    {
        $correlationId = (string) Uuid::uuid7();

        // Health endpoint — no auth required
        $response = $this->withHeaders(['X-Correlation-ID' => $correlationId])
            ->getJson('/v1/health');

        $response->assertHeader('X-Correlation-ID', $correlationId);
    }

    public function test_api_generates_correlation_id_when_not_provided(): void
    {
        $response = $this->getJson('/v1/health');

        $this->assertNotEmpty($response->headers->get('X-Correlation-ID'));
    }

    // ── Test 2: FundingInitiationJob propagates correlationId into log context ─

    public function test_funding_initiation_job_injects_correlation_id_into_log_context(): void
    {
        $correlationId = 'test-corr-' . (string) Uuid::uuid7();
        $captured      = [];

        Log::listen(static function ($message) use (&$captured): void {
            $captured[] = $message->context;
        });

        // The job will fail (no real transaction) but injectCorrelationContext() runs first
        try {
            dispatch_sync(new FundingInitiationJob(
                fundingTransactionId: (string) Uuid::uuid7(),
                correlationId:        $correlationId,
            ));
        } catch (\Throwable) {
            // Expected — transaction not found is OK; we only care about log context
        }

        // At least one log entry must carry the correlation_id we set
        $hasCorrelationId = collect($captured)->contains(function (array $ctx) use ($correlationId): bool {
            return ($ctx['correlation_id'] ?? '') === $correlationId;
        });

        $this->assertTrue(
            $hasCorrelationId,
            "Expected log context to contain correlation_id='{$correlationId}'"
        );
    }
}
