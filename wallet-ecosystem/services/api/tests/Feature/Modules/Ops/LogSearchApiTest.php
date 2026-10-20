<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ops;

use App\Modules\Ops\Domain\Contracts\LogRepositoryInterface;
use App\Modules\Ops\Domain\Enums\LogSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LogSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_entry_is_searchable_by_correlation_id(): void
    {
        $this->actingAsAdmin(['ops.view_logs']);
        $correlationId = '550e8400-e29b-41d4-a716-446655440001';

        /** @var LogRepositoryInterface $repo */
        $repo = app(LogRepositoryInterface::class);
        $repo->create('api', $correlationId, LogSeverity::Info, 'Test log entry', ['key' => 'value']);

        $this->getJson("/api/v1/admin/ops/logs?correlation_id={$correlationId}")
            ->assertOk()
            ->assertJsonPath('data.0.correlation_id', $correlationId);
    }

    public function test_sanitizer_strips_sensitive_keys(): void
    {
        $this->actingAsAdmin(['ops.view_logs']);
        $correlationId = '550e8400-e29b-41d4-a716-446655440002';

        /** @var LogRepositoryInterface $repo */
        $repo = app(LogRepositoryInterface::class);
        $repo->create('api', $correlationId, LogSeverity::Info, 'Auth attempt', [
            'password' => 'secret123',
            'user_id'  => 'abc',
        ]);

        $response = $this->getJson("/api/v1/admin/ops/logs?correlation_id={$correlationId}")
            ->assertOk();

        $context = $response->json('data.0.context');
        $this->assertSame('[REDACTED]', $context['password']);
        $this->assertSame('abc', $context['user_id']);
    }

    public function test_severity_filter_returns_matching_entries(): void
    {
        $this->actingAsAdmin(['ops.view_logs']);
        $correlationId = '550e8400-e29b-41d4-a716-446655440003';

        /** @var LogRepositoryInterface $repo */
        $repo = app(LogRepositoryInterface::class);
        $repo->create('api', $correlationId, LogSeverity::Error,   'Error entry',   []);
        $repo->create('api', $correlationId, LogSeverity::Warning, 'Warning entry', []);

        $response = $this->getJson("/api/v1/admin/ops/logs?correlation_id={$correlationId}&severity=error")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('error', $response->json('data.0.severity'));
    }

    public function test_returns_403_without_permission(): void
    {
        $this->actingAsAdmin([]);
        $this->getJson('/api/v1/admin/ops/logs')->assertForbidden();
    }

    public function test_returns_401_without_token(): void
    {
        $this->getJson('/api/v1/admin/ops/logs')->assertUnauthorized();
    }
}
