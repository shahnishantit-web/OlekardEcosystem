<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Infrastructure\Models\Credential;

interface CredentialRepositoryInterface
{
    public function findByUserAndType(string $userId, string $credentialType): ?Credential;

    public function create(array $attributes): Credential;

    public function updateHash(string $id, string $credentialHash): void;

    public function updateStatus(string $id, string $status): void;

    public function incrementFailed(string $id): void;

    public function resetFailed(string $id): void;

    public function findByFido2CredentialId(string $credentialId): ?Credential;

    public function updateFido2SignCount(string $id, int $newCount): void;
}
