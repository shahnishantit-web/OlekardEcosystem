<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Models\RiskAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T103: SC-6 (create → investigate → resolve) and SC-7 (concurrent stale version → 409).
 */
final class AlertLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    // ── SC-6: Create → Investigate → Resolve false positive ──────────────────

    /** SC-6: Create risk alert via API returns 201 with sla_due_at */
    public function test_sc6_create_alert_returns_201(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $subjectId = Str::uuid()->toString();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/alerts', [
                'alert_type'  => 'pattern',
                'severity'    => 'high',
                'subject_type' => 'customer',
                'subject_id'  => $subjectId,
                'title'       => 'Unusual spending pattern detected',
                'description' => 'Three large transactions in 10 minutes.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'open')
            ->assertJsonStructure(['id', 'alert_number', 'alert_type', 'status', 'severity', 'sla_due_at']);

        $this->assertNotNull($response->json('sla_due_at'));
    }

    /** SC-6: Investigate alert transitions to under_investigation */
    public function test_sc6_investigate_alert(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $alert   = $this->seedOpenAlert();
        $analystId = Str::uuid()->toString();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/investigate-risk-alert', [
                'alert_id'    => $alert->id,
                'assigned_to' => $analystId,
                'version'     => $alert->version,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'under_investigation')
            ->assertJsonPath('assigned_to', $analystId);

        $this->assertDatabaseHas('risk.risk_alerts', [
            'id'     => $alert->id,
            'status' => 'under_investigation',
        ], 'risk');
    }

    /** SC-6: Resolve as false_positive transitions to resolved_false_positive */
    public function test_sc6_resolve_false_positive(): void
    {
        $admin  = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $alert  = $this->seedOpenAlert();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/resolve-risk-alert', [
                'alert_id'  => $alert->id,
                'decision'  => 'clear',
                'rationale' => 'Investigated and confirmed no suspicious activity.',
                'version'   => $alert->version,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'resolved_false_positive');

        $this->assertDatabaseHas('risk.risk_alerts', [
            'id'     => $alert->id,
            'status' => 'resolved_false_positive',
        ], 'risk');

        $this->assertDatabaseHas('risk.risk_decisions', [
            'alert_id' => $alert->id,
            'decision' => 'clear',
        ], 'risk');
    }

    /** SC-6: Full lifecycle — create → investigate → resolve */
    public function test_sc6_full_lifecycle_create_investigate_resolve(): void
    {
        $admin     = $this->createAuthenticatedAdmin(['risk.manage_alerts', 'risk.view_alerts']);
        $subjectId = Str::uuid()->toString();

        // 1. Create
        $createResponse = $this->actingAs($admin)
            ->postJson('/v1/risk/alerts', [
                'alert_type'   => 'velocity',
                'severity'     => 'medium',
                'subject_type' => 'customer',
                'subject_id'   => $subjectId,
                'title'        => 'Velocity limit exceeded',
                'description'  => '12 transactions in 1 hour.',
            ]);

        $createResponse->assertStatus(201);
        $alertId = $createResponse->json('id');
        $version = $createResponse->json('version');

        // 2. Investigate
        $investigateResponse = $this->actingAs($admin)
            ->postJson('/v1/risk/investigate-risk-alert', [
                'alert_id'    => $alertId,
                'assigned_to' => Str::uuid()->toString(),
                'version'     => $version,
            ]);

        $investigateResponse->assertStatus(200)
            ->assertJsonPath('status', 'under_investigation');

        $version = $investigateResponse->json('version');

        // 3. Resolve
        $resolveResponse = $this->actingAs($admin)
            ->postJson('/v1/risk/resolve-risk-alert', [
                'alert_id'  => $alertId,
                'decision'  => 'clear',
                'rationale' => 'Reviewed all transactions — no suspicious activity found.',
                'version'   => $version,
            ]);

        $resolveResponse->assertStatus(200)
            ->assertJsonPath('status', 'resolved_false_positive');
    }

    // ── SC-7: Concurrent version mismatch → 409 ────────────────────────────────

    /** SC-7: Investigating with a stale version returns 409 */
    public function test_sc7_investigate_stale_version_returns_409(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $alert = $this->seedOpenAlert();

        // First investigator grabs the alert
        $this->actingAs($admin)
            ->postJson('/v1/risk/investigate-risk-alert', [
                'alert_id'    => $alert->id,
                'assigned_to' => Str::uuid()->toString(),
                'version'     => $alert->version,
            ])
            ->assertStatus(200);

        // Second investigator uses the original (now stale) version
        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/investigate-risk-alert', [
                'alert_id'    => $alert->id,
                'assigned_to' => Str::uuid()->toString(),
                'version'     => $alert->version,  // stale
            ]);

        $response->assertStatus(409);
    }

    /** SC-7: Resolving with a stale version returns 409 */
    public function test_sc7_resolve_stale_version_returns_409(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $alert = $this->seedOpenAlert();
        $originalVersion = $alert->version;

        // First resolver succeeds
        $this->actingAs($admin)
            ->postJson('/v1/risk/resolve-risk-alert', [
                'alert_id'  => $alert->id,
                'decision'  => 'clear',
                'rationale' => 'No suspicious activity after investigation.',
                'version'   => $originalVersion,
            ])
            ->assertStatus(200);

        // Second resolver uses original version — stale
        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/resolve-risk-alert', [
                'alert_id'  => $alert->id,
                'decision'  => 'block',
                'rationale' => 'Actually found suspicious activity.',
                'version'   => $originalVersion,
            ]);

        $response->assertStatus(409);
    }

    // ── List & Get endpoints ──────────────────────────────────────────────────

    /** List alerts returns paginated collection */
    public function test_list_alerts_returns_paginated(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.view_alerts']);

        $this->seedOpenAlert();
        $this->seedOpenAlert();

        $response = $this->actingAs($admin)
            ->getJson('/v1/risk/alerts');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    /** List alerts filters by severity */
    public function test_list_alerts_filter_by_severity(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.view_alerts']);
        $this->seedOpenAlert(['severity' => 'critical']);
        $this->seedOpenAlert(['severity' => 'low']);

        $response = $this->actingAs($admin)
            ->getJson('/v1/risk/alerts?severity=critical');

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $item) {
            $this->assertSame('critical', $item['severity']);
        }
    }

    /** Get alert returns full detail with decisions */
    public function test_get_alert_returns_detail(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.view_alerts']);
        $alert = $this->seedOpenAlert();

        $response = $this->actingAs($admin)
            ->getJson("/v1/risk/alerts/{$alert->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $alert->id)
            ->assertJsonStructure(['id', 'alert_type', 'status', 'severity', 'decisions']);
    }

    /** Get unknown alert returns 404 */
    public function test_get_unknown_alert_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.view_alerts']);

        $this->actingAs($admin)
            ->getJson('/v1/risk/alerts/'.Str::uuid())
            ->assertStatus(404);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    /** Unauthenticated requests return 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/alerts', [])->assertStatus(401);
        $this->getJson('/v1/risk/alerts')->assertStatus(401);
    }

    /** create alert duplicate correlation_id returns 409 */
    public function test_create_alert_duplicate_correlation_returns_409(): void
    {
        $admin         = $this->createAuthenticatedAdmin(['risk.manage_alerts']);
        $correlationId = Str::uuid()->toString();
        $subjectId     = Str::uuid()->toString();

        $payload = [
            'alert_type'     => 'velocity',
            'severity'       => 'medium',
            'subject_type'   => 'customer',
            'subject_id'     => $subjectId,
            'title'          => 'Duplicate alert',
            'description'    => 'Testing duplicate detection.',
            'correlation_id' => $correlationId,
        ];

        $this->actingAs($admin)
            ->postJson('/v1/risk/alerts', $payload)
            ->assertStatus(201);

        $this->actingAs($admin)
            ->postJson('/v1/risk/alerts', $payload)
            ->assertStatus(409);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedOpenAlert(array $overrides = []): RiskAlert
    {
        return RiskAlert::create(array_merge([
            'id'           => (string) Str::orderedUuid(),
            'alert_type'   => AlertType::Pattern->value,
            'status'       => AlertStatus::Open->value,
            'severity'     => 'medium',
            'subject_type' => 'customer',
            'subject_id'   => Str::uuid()->toString(),
            'title'        => 'Seeded Test Alert',
            'description'  => 'Created by AlertLifecycleTest.',
            'created_by'   => Str::uuid()->toString(),
            'updated_by'   => Str::uuid()->toString(),
            'version'      => 1,
            'sla_due_at'   => now()->addHours(72),
        ], $overrides));
    }
}
