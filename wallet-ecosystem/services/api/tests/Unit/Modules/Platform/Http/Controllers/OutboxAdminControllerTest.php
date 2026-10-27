<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\OutboxDeadLetterServiceInterface;
use App\Modules\Platform\Domain\Contracts\OutboxQueueHealthServiceInterface;
use App\Modules\Platform\Domain\Exceptions\OutboxConflictException;
use App\Modules\Platform\Infrastructure\Http\Controllers\OutboxAdminController;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class OutboxAdminControllerTest extends TestCase
{
    private OutboxDeadLetterServiceInterface&MockObject  $deadLetterService;
    private OutboxQueueHealthServiceInterface&MockObject $healthService;
    private OutboxAdminController                        $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deadLetterService = $this->createMock(OutboxDeadLetterServiceInterface::class);
        $this->healthService     = $this->createMock(OutboxQueueHealthServiceInterface::class);
        $this->controller        = new OutboxAdminController($this->deadLetterService, $this->healthService);
    }

    // ── deadLetters() — delegates to service ─────────────────────────────────

    public function test_returns_paginated_dead_letter_events(): void
    {
        $this->deadLetterService->method('listDeadLetters')
            ->willReturn(['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0]]);

        $response = $this->controller->deadLetters(new Request());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
    }

    public function test_filters_by_event_name(): void
    {
        $this->deadLetterService->expects($this->once())
            ->method('listDeadLetters')
            ->with($this->callback(fn (array $f) => ($f['event_name'] ?? '') === 'payment.confirmed'), 20)
            ->willReturn(['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0]]);

        $response = $this->controller->deadLetters(new Request(['event_name' => 'payment.confirmed']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_filters_by_correlation_id(): void
    {
        $corrId = '01932b00-0000-7000-8000-998877665544';
        $this->deadLetterService->expects($this->once())
            ->method('listDeadLetters')
            ->with($this->callback(fn (array $f) => ($f['correlation_id'] ?? '') === $corrId), 20)
            ->willReturn(['data' => [], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0]]);

        $response = $this->controller->deadLetters(new Request(['correlation_id' => $corrId]));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_response_includes_last_error_field(): void
    {
        $item = [
            'id'             => '01932b00-0000-7000-8000-aabbccddeeff',
            'event_name'     => 'payment.confirmed',
            'aggregate_id'   => '01932b00-0000-7000-8000-112233445566',
            'aggregate_type' => 'payment_intent',
            'attempts'       => 5,
            'last_error'     => 'Connection timeout on consumer',
            'correlation_id' => '01932b00-0000-7000-8000-998877665544',
            'causation_id'   => null,
            'created_at'     => null,
            'updated_at'     => null,
        ];

        $this->deadLetterService->method('listDeadLetters')
            ->willReturn(['data' => [$item], 'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 1]]);

        $response = $this->controller->deadLetters(new Request());
        $body     = json_decode($response->getContent(), true);

        $this->assertNotEmpty($body['data']);
        $this->assertArrayHasKey('last_error', $body['data'][0]);
        $this->assertSame('Connection timeout on consumer', $body['data'][0]['last_error']);
    }

    // ── replay() ─────────────────────────────────────────────────────────────

    public function test_replay_delegates_to_dead_letter_service(): void
    {
        $id    = '01932b00-0000-7000-8000-aabbccddeeff';
        $event = new OutboxEventModel();
        $event->setRawAttributes([
            'id'         => $id,
            'event_name' => 'payment.confirmed',
            'status'     => 'pending',
            'attempts'   => 0,
        ]);

        $this->deadLetterService->expects($this->once())->method('replay')->with($id)->willReturn($event);

        $response = $this->controller->replay($id, new Request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_replay_maps_not_found_exception_to_404(): void
    {
        $this->deadLetterService->method('replay')->willThrowException(new ModelNotFoundException());

        $response = $this->controller->replay('nonexistent-id', new Request());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_replay_maps_conflict_exception_to_409(): void
    {
        $this->deadLetterService->method('replay')
            ->willThrowException(new OutboxConflictException('Event is not in dead_letter status.'));

        $response = $this->controller->replay('some-id', new Request());

        $this->assertSame(409, $response->getStatusCode());
    }
}
