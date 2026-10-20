<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class IdempotencyKeyFactory
{
    private string $actorId;
    private string $idempotencyKey;
    private string $status = 'completed';
    private string $httpMethod = 'POST';
    private string $httpPath = '/v1/test';
    private int $responseStatus = 200;
    private array $responseBody = [];

    public function __construct()
    {
        $this->actorId        = (string) Uuid::uuid7();
        $this->idempotencyKey = (string) Uuid::uuid7();
    }

    public static function new(): self
    {
        return new self();
    }

    public function withActorId(string $actorId): self
    {
        $clone          = clone $this;
        $clone->actorId = $actorId;
        return $clone;
    }

    public function withKey(string $key): self
    {
        $clone                 = clone $this;
        $clone->idempotencyKey = $key;
        return $clone;
    }

    public function processing(): self
    {
        $clone         = clone $this;
        $clone->status = 'processing';
        return $clone;
    }

    public function withResponseBody(array $body): self
    {
        $clone               = clone $this;
        $clone->responseBody = $body;
        return $clone;
    }

    public function create(): object
    {
        $id = (string) Uuid::uuid7();

        DB::connection('platform')->table('platform.idempotency_records')->insert([
            'id'              => $id,
            'idempotency_key' => $this->idempotencyKey,
            'actor_id'        => $this->actorId,
            'actor_type'      => 'USER',
            'request_hash'    => sha1($this->httpMethod.$this->httpPath),
            'http_method'     => $this->httpMethod,
            'http_path'       => $this->httpPath,
            'status'          => $this->status,
            'attempt_count'   => 1,
            'response_status' => $this->status === 'completed' ? $this->responseStatus : null,
            'response_body'   => $this->status === 'completed' ? json_encode($this->responseBody) : null,
            'created_at'      => now(),
            'expires_at'      => now()->addDay(),
        ]);

        return (object) ['id' => $id, 'actor_id' => $this->actorId, 'idempotency_key' => $this->idempotencyKey];
    }
}
