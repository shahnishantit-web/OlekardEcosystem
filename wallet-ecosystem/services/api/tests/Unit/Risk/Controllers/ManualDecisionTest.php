<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Controllers;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Services\AlertService;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Application\Services\WatchlistScreenerService;
use App\Modules\Risk\Domain\Contracts\RiskEvaluatorInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\RiskDecision;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US-7/US-8: Manual allow/block decisions via API.
 */
final class ManualDecisionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));

        $configStub = $this->createStub(ConfigurationServiceInterface::class);
        $configStub->method('getOrDefault')->willReturnArgument(1);
        $this->instance(ConfigurationServiceInterface::class, $configStub);
    }

    private function createEvaluation(array $overrides = []): RiskEvaluation
    {
        $eval = new RiskEvaluation();
        $eval->setRawAttributes(array_merge([
            'id'                    => (string) Str::orderedUuid(),
            'idempotency_key'       => (string) Str::uuid(),
            'transaction_reference' => 'TXN-'.Str::random(8),
            'transaction_type'      => 'transfer',
            'amount'                => '100.0000',
            'currency'              => 'BWP',
            'customer_id'           => (string) Str::orderedUuid(),
            'overall_score'         => 75.00,
            'risk_level'            => RiskLevel::High->value,
            'decision'              => RiskDecisionEnum::Review->value,
            'evaluation_ms'         => 50,
            'evaluated_at'          => now()->toIso8601String(),
            'rules_applied'         => '[]',
        ], $overrides));
        $eval->save();

        return $eval;
    }

    private function payload(string $evaluationId, array $overrides = []): array
    {
        return array_merge([
            'evaluation_id' => $evaluationId,
            'rationale'     => 'Manual review completed, transaction is legitimate.',
        ], $overrides);
    }

    // ── Allow ────────────────────────────────────────────────────────────────

    /** allow-transaction creates a RiskDecision with decision=clear */
    public function test_allow_transaction_creates_decision_clear(): void
    {
        $eval  = $this->createEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/allow-transaction', $this->payload($eval->id));

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'clear');

        $this->assertDatabaseHas('risk.risk_decisions', [
            'decision' => 'clear',
        ], 'risk');
    }

    /** allow-transaction on unknown evaluation → 404 */
    public function test_allow_unknown_evaluation_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/allow-transaction', $this->payload((string) Str::uuid()))
            ->assertStatus(404);
    }

    /** allow-transaction twice on same evaluation → 409 */
    public function test_allow_transaction_duplicate_returns_409(): void
    {
        $eval  = $this->createEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/allow-transaction', $this->payload($eval->id))
            ->assertStatus(200);

        $this->actingAs($admin)
            ->postJson('/v1/risk/allow-transaction', $this->payload($eval->id))
            ->assertStatus(409);
    }

    // ── Block ────────────────────────────────────────────────────────────────

    /** block-transaction creates a RiskDecision with decision=block */
    public function test_block_transaction_creates_decision_block(): void
    {
        $eval  = $this->createEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/block-transaction', $this->payload($eval->id));

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'block');

        $this->assertDatabaseHas('risk.risk_decisions', [
            'decision' => 'block',
        ], 'risk');
    }

    /** block-transaction on unknown evaluation → 404 */
    public function test_block_unknown_evaluation_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/block-transaction', $this->payload((string) Str::uuid()))
            ->assertStatus(404);
    }

    /** block-transaction twice on same evaluation → 409 */
    public function test_block_transaction_duplicate_returns_409(): void
    {
        $eval  = $this->createEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/block-transaction', $this->payload($eval->id))
            ->assertStatus(200);

        $this->actingAs($admin)
            ->postJson('/v1/risk/block-transaction', $this->payload($eval->id))
            ->assertStatus(409);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /** rationale too short → 422 */
    public function test_short_rationale_returns_422(): void
    {
        $eval  = $this->createEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/allow-transaction', $this->payload($eval->id, ['rationale' => 'short']))
            ->assertStatus(422);
    }

    /** Missing required fields → 422 */
    public function test_missing_fields_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/allow-transaction', [])
            ->assertStatus(422);
    }

    /** Unauthenticated returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/allow-transaction', $this->payload((string) Str::uuid()))
            ->assertStatus(401);
    }
}
