<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Credential;

final class EloquentCredentialRepository implements CredentialRepositoryInterface
{
    public function findByUserAndType(string $userId, string $credentialType): ?Credential
    {
        return Credential::where('user_id', $userId)
            ->where('credential_type', $credentialType)
            ->first();
    }

    public function create(array $attributes): Credential
    {
        return Credential::create($attributes);
    }

    public function updateHash(string $id, string $credentialHash): void
    {
        Credential::where('id', $id)->update(['credential_hash' => $credentialHash]);
    }

    public function updateStatus(string $id, string $status): void
    {
        Credential::where('id', $id)->update(['status' => $status]);
    }

    public function incrementFailed(string $id): void
    {
        Credential::where('id', $id)->increment('failed_attempt_count');
    }

    public function resetFailed(string $id): void
    {
        Credential::where('id', $id)->update(['failed_attempt_count' => 0]);
    }

    public function findByFido2CredentialId(string $credentialId): ?Credential
    {
        return Credential::where('fido2_credential_id', $credentialId)
            ->where('status', 'active')
            ->first();
    }

    public function updateFido2SignCount(string $id, int $newCount): void
    {
        Credential::where('id', $id)->update(['fido2_sign_count' => $newCount]);
    }
}
