<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Modules\Commission\Domain\Enums\CommissionRuleStatus;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionRuleApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(EventPublisherInterface::class, $this->createStub(EventPublisherInterface::class));
    }

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Flat BWP 2.00 Cash-In',
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'valid_from' => '2026-01-01',
        ], $overrides);
    }

    public function test_admin_can_create_rule_returns_201(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.rules.create']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/commission-rules', $this->rulePayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.rate', 200);
    }

    public function test_unauthenticated_create_returns_401(): void
    {
        $this->postJson('/v1/commission-rules', $this->rulePayload())
            ->assertStatus(401);
    }

    public function test_missing_permission_returns_403(): void
    {
        $user = $this->createAuthenticatedUser([]);

        $this->actingAs($user)
            ->postJson('/v1/commission-rules', $this->rulePayload())
            ->assertStatus(403);
    }

    public function test_invalid_rate_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.rules.create']);

        $this->actingAs($admin)
            ->postJson('/v1/commission-rules', $this->rulePayload(['rate' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.0.field', 'rate');
    }

    public function test_admin_can_list_rules_returns_200(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.rules.view']);
        CommissionRuleModel::factory()->count(3)->create();

        $this->actingAs($admin)
            ->getJson('/v1/commission-rules')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_get_single_rule_returns_200(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.rules.view']);
        $rule  = CommissionRuleModel::factory()->create();

        $this->actingAs($admin)
            ->getJson("/v1/commission-rules/{$rule->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $rule->id);
    }

    public function test_patch_supersedes_old_and_creates_new_returns_200(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.rules.update']);
        $rule  = CommissionRuleModel::factory()->create(['rate' => 200]);

        $response = $this->actingAs($admin)
            ->patchJson("/v1/commission-rules/{$rule->id}", ['rate' => 300]);

        $response->assertStatus(200)
            ->assertJsonPath('data.rate', 300)
            ->assertJsonPath('data.status', 'active');

        $rule->refresh();
        $this->assertEquals(CommissionRuleStatus::Superseded, $rule->status);
    }

    public function test_delete_deactivates_rule_returns_200(): void
    {
        $admin = $this->createAuthenticatedAdmin(['commission.rules.delete']);
        $rule  = CommissionRuleModel::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/v1/commission-rules/{$rule->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'expired');
    }
}
