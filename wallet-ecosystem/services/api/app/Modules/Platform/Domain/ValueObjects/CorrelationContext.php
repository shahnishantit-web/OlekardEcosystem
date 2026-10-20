<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Platform\Domain\Contracts\ActorContract;
use App\Modules\Platform\Domain\Contracts\CorrelationContextInterface;

final class CorrelationContext implements CorrelationContextInterface
{
    public function __construct(
        private readonly string $correlationId,
        private readonly string $requestId,
        private readonly ?ActorContract $actor = null,
    ) {}

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function actor(): ?ActorContract
    {
        return $this->actor;
    }

    public function withCorrelationId(string $id): static
    {
        return new static($id, $this->requestId, $this->actor);
    }

    public function withActor(ActorContract $actor): static
    {
        return new static($this->correlationId, $this->requestId, $actor);
    }
}
