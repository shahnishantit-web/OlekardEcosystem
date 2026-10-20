<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Domain\Enums\AlertSeverity;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertRuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_alert_rules(): void
    {
        $this->actingAsAdmin();

        AlertRuleModel::create([
            'id'                    => Str::orderedUuid()->toString(),
            'name'                  => 'High Error Rate',
            'metric_condition'      => ['service_name' => 'api', 'metric_name' => 'http.error_rate', 'operator' => 'gt', 'threshold' => 0.05, 'window_minutes' => 5],
            'severity'              => AlertSeverity::Warning->value,
            'notification_channels' => ['email'],
            'is_enabled'            => true,
            'created_by'            => Str::uuid()->toString(),
        ]);

        $this->getJson('/v1/admin/ops/alert-rules')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'High Error Rate');
    }

    public function test_can_create_alert_rule(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/v1/admin/ops/alert-rules', [
            'name'                  => 'DB Slow Queries',
            'metric_condition'      => ['service_name' => 'db', 'metric_name' => 'slow_queries', 'operator' => 'gt', 'threshold' => 10, 'window_minutes' => 10],
            'severity'              => 'warning',
            'notification_channels' => ['slack'],
            'is_enabled'            => true,
        ], ['Idempotency-Key' => Str::uuid()->toString()])
            ->assertCreated()
            ->assertJsonPath('data.name', 'DB Slow Queries');
    }

    public function test_can_update_alert_rule(): void
    {
        $this->actingAsAdmin();

        $rule = AlertRuleModel::create([
            'id'                    => Str::orderedUuid()->toString(),
            'name'                  => 'Old Name',
            'metric_condition'      => ['service_name' => 'api', 'metric_name' => 'errors', 'operator' => 'gt', 'threshold' => 100, 'window_minutes' => 5],
            'severity'              => AlertSeverity::Info->value,
            'notification_channels' => ['email'],
            'is_enabled'            => true,
            'created_by'            => Str::uuid()->toString(),
        ]);

        $this->putJson("/v1/admin/ops/alert-rules/{$rule->id}", [
            'name' => 'Updated Name',
        ], ['Idempotency-Key' => Str::uuid()->toString()])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_can_delete_alert_rule(): void
    {
        $this->actingAsAdmin();

        $rule = AlertRuleModel::create([
            'id'                    => Str::orderedUuid()->toString(),
            'name'                  => 'To Delete',
            'metric_condition'      => ['service_name' => 'api', 'metric_name' => 'errors', 'operator' => 'gt', 'threshold' => 100, 'window_minutes' => 5],
            'severity'              => AlertSeverity::Critical->value,
            'notification_channels' => ['pagerduty'],
            'is_enabled'            => false,
            'created_by'            => Str::uuid()->toString(),
        ]);

        $this->deleteJson("/v1/admin/ops/alert-rules/{$rule->id}", [], ['Idempotency-Key' => Str::uuid()->toString()])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('ops.alert_rules', ['id' => $rule->id]);
    }

    public function test_returns_404_for_unknown_rule(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/v1/admin/ops/alert-rules/' . Str::uuid())
            ->assertNotFound();
    }
}
