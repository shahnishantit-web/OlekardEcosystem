<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\SuspiciousActivityReportModel;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class SARTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-sar']);
    }

    public function test_post_generates_sar_with_valid_report_number(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['compliance.report']);
        $userId  = (string) Uuid::uuid7();
        $key     = (string) Uuid::uuid7();

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/sars', [
                'subject_user_id'  => $userId,
                'reason_code'      => 'UNUSUAL_ACTIVITY',
                'reason_narrative' => 'Multiple high-value transactions detected.',
            ]);

        $response->assertCreated();
        $reportNumber = $response->json('data.report_number');
        $this->assertMatchesRegularExpression('/^SAR-\d{8}-\d{6}$/', $reportNumber);
    }

    public function test_all_required_jsonb_fields_present_and_non_null(): void
    {
        $admin         = $this->createAuthenticatedAdmin(['compliance.report']);
        $subjectUserId = (string) Uuid::uuid7();
        $txId          = (string) Uuid::uuid7();
        $key           = (string) Uuid::uuid7();

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/sars', [
                'subject_user_id'         => $subjectUserId,
                'reason_code'             => 'SUSPICIOUS_TRANSFER',
                'reason_narrative'        => 'Transfers to unknown beneficiaries.',
                'flagged_transaction_ids' => [$txId],
            ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertArrayHasKey('subject_identity_snapshot', $data);
        $this->assertArrayHasKey('flagged_transaction_ids', $data);
        $this->assertArrayHasKey('aml_alert_history', $data);
        $this->assertArrayHasKey('risk_score_timeline', $data);
        $this->assertArrayHasKey('reason_narrative', $data);

        $this->assertSame([$txId], $data['flagged_transaction_ids']);
        $this->assertIsArray($data['aml_alert_history']);
        $this->assertIsArray($data['risk_score_timeline']);
        $this->assertNotEmpty($data['reason_narrative']);
    }

    public function test_subject_with_no_aml_alerts_produces_sar_with_empty_alert_history(): void
    {
        $admin         = $this->createAuthenticatedAdmin(['compliance.report']);
        $subjectUserId = (string) Uuid::uuid7();
        $key           = (string) Uuid::uuid7();

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/sars', [
                'subject_user_id'  => $subjectUserId,
                'reason_code'      => 'PRECAUTIONARY',
                'reason_narrative' => 'Flagged by manual review.',
            ]);

        $response->assertCreated();
        $data = $response->json('data');
        $this->assertSame([], $data['aml_alert_history']);
        // Per T075: reason_narrative must contain "no alerts found" for subjects with no AML events
        $this->assertStringContainsString('no AML alert', $data['reason_narrative']);
    }

    public function test_idempotent_repeat_returns_same_sar_id(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['compliance.report']);
        $userId  = (string) Uuid::uuid7();
        $key     = (string) Uuid::uuid7();

        $payload = [
            'subject_user_id'  => $userId,
            'reason_code'      => 'REPEAT_ACTIVITY',
            'reason_narrative' => 'Second submission test.',
        ];

        $r1 = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/sars', $payload);
        $r2 = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/sars', $payload);

        $r1->assertCreated();
        $r2->assertOk();
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
    }

    public function test_sar_is_marked_incomplete_when_identity_snapshot_unavailable(): void
    {
        $this->mock(IdentityModuleContract::class, function ($mock): void {
            $mock->shouldReceive('hasPermission')->andReturn(true);
            $mock->shouldReceive('getIdentitySnapshot')
                 ->once()
                 ->andThrow(new \BadMethodCallException('getIdentitySnapshot not yet implemented by E01'));
        });

        $admin  = $this->createAuthenticatedAdmin(['compliance.report']);
        $userId = (string) Uuid::uuid7();
        $key    = (string) Uuid::uuid7();

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/v1/compliance/sars', [
                'subject_user_id'  => $userId,
                'reason_code'      => 'UNUSUAL_ACTIVITY',
                'reason_narrative' => 'Testing incomplete status path.',
            ]);

        $response->assertCreated();

        $snapshot = $response->json('data.subject_identity_snapshot');
        $this->assertSame('snapshot_unavailable', $snapshot['status']);

        $sarId = $response->json('data.id');
        $sar   = SuspiciousActivityReportModel::find($sarId);
        $this->assertNotNull($sar);
        $this->assertSame('incomplete', $sar->status);
    }
}
