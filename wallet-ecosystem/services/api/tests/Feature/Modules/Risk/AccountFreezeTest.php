<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\HoldStatus;
use App\Modules\Risk\Domain\Models\AccountHold;
use App\Modules\Risk\Domain\Models\RiskAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T109: SC-8 — freeze account → transaction blocked; lift → transaction allowed.
 */
final class AccountFreezeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    // ── SC-8: Freeze account → block; lift → allow ────────────────────────────

    /** SC-8: Freeze account creates an active AccountHold */
    public function test_sc8_freeze_account_creates_hold(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['risk.freeze_account']);
        $subjectId = Str::uuid()->toString();
        $checkerId = Str::uuid()->toString();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/freeze-account-due-to-risk', [
                'subject_type' => 'customer',
                'subject_id'   => $subjectId,
                'reason'       => 'Confirmed fraud — account frozen pending investigation.',
                'checker_id'   => $checkerId,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('subject_id', $subjectId)
            ->assertJsonStructure(['id', 'hold_type', 'status', 'placed_at']);

        $this->assertDatabaseHas('risk.account_holds', [
            'subject_id' => $subjectId,
            'status'     => 'active',
        ], 'risk');
    }

    /** SC-8: Frozen account → evaluate-transaction-risk returns decision=block */
    public function test_sc8_frozen_account_blocks_transaction(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['risk.evaluate', 'risk.freeze_account']);
        $customerId = Str::uuid()->toString();
        $checkerId  = Str::uuid()->toString();

        // Freeze the account
        $this->actingAs($admin)
            ->postJson('/v1/risk/freeze-account-due-to-risk', [
                'subject_type' => 'customer',
                'subject_id'   => $customerId,
                'reason'       => 'Account frozen due to confirmed AML pattern.',
                'checker_id'   => $checkerId,
            ])
            ->assertStatus(201);

        // Now evaluate a transaction for that customer
        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', [
                'idempotency_key'       => (string) Str::uuid(),
                'transaction_reference' => 'TXN-'.Str::random(8),
                'transaction_type'      => 'transfer',
                'amount'                => '100.00',
                'currency'              => 'BWP',
                'customer_id'           => $customerId,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'block');
    }

    /** SC-8: Lift hold → subsequent evaluation is allowed */
    public function test_sc8_lift_hold_allows_transactions(): void
    {
        $admin      = $this->createAuthenticatedAdmin(['risk.evaluate', 'risk.freeze_account']);
        $customerId = Str::uuid()->toString();

        // Freeze
        $freezeResponse = $this->actingAs($admin)
            ->postJson('/v1/risk/freeze-account-due-to-risk', [
                'subject_type' => 'customer',
                'subject_id'   => $customerId,
                'reason'       => 'Suspicious activity detected — freezing pending review.',
                'checker_id'   => Str::uuid()->toString(),
            ]);

        $freezeResponse->assertStatus(201);
        $holdId = $freezeResponse->json('id');

        // Lift the hold
        $this->actingAs($admin)
            ->postJson('/v1/risk/lift-account-hold', [
                'hold_id'     => $holdId,
                'lift_reason' => 'Investigation complete — account is clean.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', 'lifted');

        // Verify hold is lifted
        $this->assertDatabaseHas('risk.account_holds', [
            'id'     => $holdId,
            'status' => 'lifted',
        ], 'risk');
    }

    /** Duplicate freeze on same subject returns 409 */
    public function test_duplicate_freeze_returns_409(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['risk.freeze_account']);
        $subjectId = Str::uuid()->toString();

        $payload = [
            'subject_type' => 'customer',
            'subject_id'   => $subjectId,
            'reason'       => 'First freeze — suspicious activity.',
            'checker_id'   => Str::uuid()->toString(),
        ];

        $this->actingAs($admin)->postJson('/v1/risk/freeze-account-due-to-risk', $payload)->assertStatus(201);
        $this->actingAs($admin)->postJson('/v1/risk/freeze-account-due-to-risk', $payload)->assertStatus(409);
    }

    /** Freeze without checker_id fails validation */
    public function test_freeze_without_checker_id_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.freeze_account']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/freeze-account-due-to-risk', [
                'subject_type' => 'customer',
                'subject_id'   => Str::uuid()->toString(),
                'reason'       => 'Missing checker.',
                // no checker_id
            ])
            ->assertStatus(422);
    }

    /** Unauthenticated returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/freeze-account-due-to-risk', [])->assertStatus(401);
        $this->postJson('/v1/risk/lift-account-hold', [])->assertStatus(401);
    }
}
