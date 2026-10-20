<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface CorrelationContextInterface
{
    public function correlationId(): string;

    public function requestId(): string;

    public function actor(): ?ActorContract;

    public function withCorrelationId(string $id): static;

    public function withActor(ActorContract $actor): static;
}
