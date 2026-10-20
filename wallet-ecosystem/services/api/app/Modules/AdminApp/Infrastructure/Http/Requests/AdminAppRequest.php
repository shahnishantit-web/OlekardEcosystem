<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Ramsey\Uuid\Uuid;

abstract class AdminAppRequest extends FormRequest
{
    public function correlationId(): string
    {
        return $this->header('X-Correlation-ID') ?? Uuid::uuid4()->toString();
    }

    public function idempotencyKey(): ?string
    {
        return $this->header('Idempotency-Key');
    }

    public function actorId(): string
    {
        return (string) $this->user()?->id;
    }

    public function authorize(): bool
    {
        return true;
    }
}
