<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface ActorContract
{
    public function id(): string;

    public function type(): string;

    public function isUser(): bool;

    public function isService(): bool;
}
