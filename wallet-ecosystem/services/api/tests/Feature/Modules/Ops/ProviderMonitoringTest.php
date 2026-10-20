<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Domain\Enums\ProviderStatus;
use App\Modules\Ops\Infrastructure\Models\ProviderStatusModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_all_provider_statuses(): void
    {
        $this->actingAsAdmin();

        ProviderStatusModel::create([
            'id'                   => \Illuminate\Support\Str::orderedUuid()->toString(),
            'provider_type'        => 'sms',
            'status'               => ProviderStatus::Up->value,
            'consecutive_failures' => 0,
        ]);

        $this->getJson('/v1/admin/ops/providers')
            ->assertOk()
            ->assertJsonPath('data.0.provider_type', 'sms')
            ->assertJsonPath('data.0.status', 'up');
    }

    public function test_show_returns_404_for_unknown_provider(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/v1/admin/ops/providers/unknown_provider')
            ->assertNotFound();
    }
}
