<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisasterRecoveryStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dr_status_returns_expected_keys(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/v1/admin/ops/dr/status')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['runbook_version', 'last_dr_test_at', 'rto_hours', 'rpo_minutes', 'runbook_url'],
            ]);
    }

    public function test_record_dr_test_requires_outcome(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/v1/admin/ops/dr/tests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['outcome']);
    }

    public function test_can_record_dr_test(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/v1/admin/ops/dr/tests', [
            'outcome' => 'pass',
            'notes'   => 'Full recovery completed in 3h 40m — within RTO.',
        ])->assertStatus(201)
          ->assertJsonPath('data.outcome', 'pass');
    }
}
