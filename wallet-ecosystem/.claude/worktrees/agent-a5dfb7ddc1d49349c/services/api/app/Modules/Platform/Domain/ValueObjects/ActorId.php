<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\ValueObjects;

use Ramsey\Uuid\Uuid;

final class ActorId
{
    private const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    private function __construct(
        private readonly string $id,
        private readonly string $type,
    ) {}

    public static function fromUser(string $userId): self
    {
        return new self($userId, 'USER');
    }

    public static function fromService(string $serviceId): self
    {
        return new self($serviceId, 'SERVICE');
    }

    public static function system(): self
    {
        return new self(self::ZERO_UUID, 'SYSTEM');
    }

    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['type']);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isSystem(): bool
    {
        return $this->id === self::ZERO_UUID && $this->type === 'SYSTEM';
    }

    public function isResolved(): bool
    {
        return $this->id !== '';
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type];
    }
}
