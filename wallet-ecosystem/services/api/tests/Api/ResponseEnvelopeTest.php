<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

test('health endpoint returns correct envelope structure', function () {
    $response = $this->getJson('/health');

    $response->assertStatus(200)
        ->assertJsonStructure(['status']);
});

test('ready endpoint includes correlation_id in response', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid4();

    $response = $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/ready');

    $response->assertHeader('X-Correlation-ID');
});

test('error responses include error.code field', function () {
    $response = $this->getJson('/api/v1/nonexistent-route-12345');

    $response->assertStatus(404)
        ->assertJsonStructure([
            'success',
            'data',
            'error' => ['code', 'message'],
            'correlation_id',
        ]);
});

test('correlation_id in response matches X-Correlation-ID header', function () {
    $correlationId = (string) \Ramsey\Uuid\Uuid::uuid4();

    $response = $this->withHeaders(['X-Correlation-ID' => $correlationId])
        ->getJson('/health');

    $response->assertHeader('X-Correlation-ID', $correlationId);
});
