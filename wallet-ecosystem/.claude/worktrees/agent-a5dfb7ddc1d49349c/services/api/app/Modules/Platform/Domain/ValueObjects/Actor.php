<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Platform\Domain\Contracts\ActorContract;

final class Actor implements ActorContract
{
    private const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly string $id,
        private readonly string $type, // user | service | system
    ) {}

    public static function user(string $id): self
    {
        return new self($id, 'user');
    }

    public static function service(string $id): self
    {
        return new self($id, 'service');
    }

    public static function system(): self
    {
        return new self(self::ZERO_UUID, 'system');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isUser(): bool
    {
        return $this->type === 'user';
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function isSystem(): bool
    {
        return $this->type === 'system';
    }
}
