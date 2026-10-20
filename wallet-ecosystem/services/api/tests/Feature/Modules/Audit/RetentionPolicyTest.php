<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Audit;

use App\Modules\Audit\Infrastructure\Models\RetentionPolicyModel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class RetentionPolicyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.hmac_key' => 'test-hmac-retention-feature']);
    }

    public function test_create_policy_records_config_audit_event(): void
    {
        $admin = $this->createAuthenticatedAdmin(['compliance.config']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/compliance/retention-policies', [
                'data_category'          => 'audit.authentication',
                'minimum_retention_days' => 90,
                'post_retention_action'  => 'archive',
            ]);

        $response->assertCreated()
                 ->assertJsonPath('data.data_category', 'audit.authentication')
                 ->assertJsonPath('data.minimum_retention_days', 90)
                 ->assertJsonPath('data.post_retention_action', 'archive');

        // The createPolicy call audits via AuditModuleContract — verify audit_event was written
        $this->assertDatabaseHas('audit.audit_events', [
            'event_type' => 'config.retention_policy.created',
        ], 'platform');
    }

    public function test_update_shortening_period_sets_enforcement_hold_until(): void
    {
        $admin = $this->createAuthenticatedAdmin(['compliance.config']);

        // Create initial policy with 90-day retention
        $createResponse = $this->actingAs($admin)
            ->postJson('/v1/compliance/retention-policies', [
                'data_category'          => 'compliance.history',
                'minimum_retention_days' => 90,
                'post_retention_action'  => 'archive',
            ]);

        $createResponse->assertCreated();
        $policyId = $createResponse->json('data.id');

        // Update to shorter retention period (30 days < 90 days)
        $updateResponse = $this->actingAs($admin)
            ->putJson("/v1/compliance/retention-policies/{$policyId}", [
                'data_category'          => 'compliance.history',
                'minimum_retention_days' => 30,
                'post_retention_action'  => 'archive',
            ]);

        $updateResponse->assertOk();

        $policy = RetentionPolicyModel::find($policyId);
        $this->assertNotNull($policy->enforcement_hold_until, 'enforcement_hold_until should be set when period is shortened');
        $this->assertTrue(
            $policy->enforcement_hold_until->gte(CarbonImmutable::today()),
            'enforcement_hold_until must be in the future (grace period)'
        );
        $this->assertSame(90, $policy->previous_minimum_retention_days);
    }

    public function test_update_lengthening_period_does_not_set_enforcement_hold(): void
    {
        $admin = $this->createAuthenticatedAdmin(['compliance.config']);

        $createResponse = $this->actingAs($admin)
            ->postJson('/v1/compliance/retention-policies', [
                'data_category'          => 'audit.financial',
                'minimum_retention_days' => 30,
                'post_retention_action'  => 'archive',
            ]);

        $createResponse->assertCreated();
        $policyId = $createResponse->json('data.id');

        // Update to longer retention (90 days > 30 days) — no grace period needed
        $updateResponse = $this->actingAs($admin)
            ->putJson("/v1/compliance/retention-policies/{$policyId}", [
                'data_category'          => 'audit.financial',
                'minimum_retention_days' => 90,
                'post_retention_action'  => 'archive',
            ]);

        $updateResponse->assertOk();

        $policy = RetentionPolicyModel::find($policyId);
        $this->assertNull($policy->enforcement_hold_until);
    }

    public function test_missing_compliance_config_permission_returns_403(): void
    {
        $customer = $this->createAuthenticatedUser([]);

        $this->actingAs($customer)
             ->postJson('/v1/compliance/retention-policies', [
                 'data_category'          => 'audit.authentication',
                 'minimum_retention_days' => 90,
                 'post_retention_action'  => 'archive',
             ])
             ->assertForbidden();
    }

    public function test_list_returns_all_policies(): void
    {
        $admin = $this->createAuthenticatedAdmin(['compliance.config']);

        $this->actingAs($admin)->postJson('/v1/compliance/retention-policies', [
            'data_category'          => 'audit.authentication',
            'minimum_retention_days' => 90,
            'post_retention_action'  => 'archive',
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/v1/compliance/retention-policies', [
            'data_category'          => 'audit.financial',
            'minimum_retention_days' => 365,
            'post_retention_action'  => 'archive',
        ])->assertCreated();

        $listResponse = $this->actingAs($admin)->getJson('/v1/compliance/retention-policies');

        $listResponse->assertOk();
        $this->assertCount(2, $listResponse->json('data'));
    }
}
