<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Models\WatchlistEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SC-4: Watchlist match → decision=block + match/alert persisted.
 * SC-4b: Non-watchlist beneficiary → decision=allow.
 * SC-4c: New beneficiary flag raises score vs established beneficiary.
 */
final class BeneficiaryRiskTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'beneficiary_id'     => (string) Str::orderedUuid(),
            'beneficiary_name'   => 'Alice Benef '.Str::random(6),
            'beneficiary_type'   => 'customer',
            'customer_id'        => (string) Str::orderedUuid(),
            'is_new_beneficiary' => false,
        ], $overrides);
    }

    private function createWatchlistEntry(string $fullName): WatchlistEntry
    {
        $entry = new WatchlistEntry();
        $entry->fill([
            'id'          => (string) Str::orderedUuid(),
            'entry_type'  => 'individual',
            'entity_type' => 'individual',
            'source'      => 'test',
            'is_active'   => true,
        ]);
        $entry->full_name = $fullName;
        $entry->save();

        return $entry;
    }

    // ── SC-4: Watchlist hit → block ──────────────────────────────────────────

    /** SC-4: beneficiary name on watchlist → decision=block */
    public function test_sc4_watchlist_beneficiary_returns_block(): void
    {
        $sanctionedName = 'Sanctioned Person '.Str::random(6);
        $this->createWatchlistEntry($sanctionedName);

        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload([
                'beneficiary_name' => $sanctionedName,
            ]));

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'block')
            ->assertJsonPath('watchlist_matches', 1);
    }

    /** SC-4: watchlist hit creates a WatchlistMatch record */
    public function test_sc4_watchlist_hit_persists_match_record(): void
    {
        $sanctionedName = 'Watchlisted One '.Str::random(6);
        $beneficiaryId  = (string) Str::orderedUuid();
        $this->createWatchlistEntry($sanctionedName);

        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload([
                'beneficiary_id'   => $beneficiaryId,
                'beneficiary_name' => $sanctionedName,
            ]));

        $this->assertDatabaseHas('risk.watchlist_matches', [
            'subject_type' => 'customer',
            'subject_id'   => $beneficiaryId,
            'match_method' => 'exact',
        ], 'risk');
    }

    /** SC-4: watchlist hit creates a RiskAlert with type=watchlist */
    public function test_sc4_watchlist_hit_creates_alert(): void
    {
        $sanctionedName = 'Alert Target '.Str::random(6);
        $beneficiaryId  = (string) Str::orderedUuid();
        $this->createWatchlistEntry($sanctionedName);

        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload([
                'beneficiary_id'   => $beneficiaryId,
                'beneficiary_name' => $sanctionedName,
            ]));

        $this->assertDatabaseHas('risk.risk_alerts', [
            'alert_type'   => 'watchlist',
            'subject_type' => 'customer',
            'subject_id'   => $beneficiaryId,
            'status'       => 'open',
        ], 'risk');
    }

    // ── SC-4b: Non-watchlist → allow ─────────────────────────────────────────

    /** SC-4b: beneficiary not on watchlist → decision=allow */
    public function test_sc4b_non_watchlist_beneficiary_returns_allow(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload([
                'beneficiary_name'   => 'Completely Unknown Person '.Str::random(8),
                'is_new_beneficiary' => false,
            ]));

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'allow')
            ->assertJsonPath('watchlist_matches', 0);
    }

    // ── SC-4c: New beneficiary baseline ──────────────────────────────────────

    /** SC-4c: new beneficiary flag yields higher risk_score than established beneficiary */
    public function test_sc4c_new_beneficiary_flag_increases_risk_score(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $name  = 'New Recipient '.Str::random(8);

        $existingResp = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload([
                'beneficiary_name'   => $name,
                'is_new_beneficiary' => false,
            ]));

        $newResp = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload([
                'beneficiary_name'   => $name,
                'is_new_beneficiary' => true,
            ]));

        $existingResp->assertStatus(200);
        $newResp->assertStatus(200);

        $this->assertGreaterThan(
            $existingResp->json('risk_score'),
            $newResp->json('risk_score'),
            'New beneficiary should produce a higher risk score'
        );
    }

    // ── Auth & Validation ────────────────────────────────────────────────────

    /** Unauthenticated request returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload())
            ->assertStatus(401);
    }

    /** Missing permission returns 403 */
    public function test_missing_permission_returns_403(): void
    {
        $user = $this->createAuthenticatedUser([]);

        $this->actingAs($user)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload())
            ->assertStatus(403);
    }

    /** Missing required fields return 422 */
    public function test_missing_required_fields_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', [])
            ->assertStatus(422);
    }

    /** Response has expected structure */
    public function test_response_structure_is_correct(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-beneficiary-risk', $this->payload())
            ->assertStatus(200)
            ->assertJsonStructure([
                'beneficiary_id',
                'is_new_beneficiary',
                'watchlist_matches',
                'risk_score',
                'decision',
            ]);
    }
}
