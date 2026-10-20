<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Session;
final class EloquentSessionRepository implements SessionRepositoryInterface
{
    public function create(array $attributes): Session
    {
        // forceCreate bypasses $fillable so the explicit 'id' (UUID) is honoured by HasUuids.
        // Without this, HasUuids::setUniqueIds() silently ignores mass-assignment-guarded 'id'.
        return Session::forceCreate($attributes);
    }

    public function findById(string $id): ?Session
    {
        return Session::find($id);
    }

    public function findByRefreshHash(string $hash): ?Session
    {
        return Session::where('refresh_token_hash', $hash)->first();
    }

    public function findByFamilyId(string $familyId): array
    {
        return Session::where('refresh_token_family_id', $familyId)->get()->all();
    }

    public function updateStatus(string $id, string $status): void
    {
        Session::where('id', $id)->update(['status' => $status]);
    }

    public function revokeAllForUser(string $userId): void
    {
        Session::where('user_id', $userId)
            ->whereNotIn('status', ['revoked', 'logged_out', 'expired'])
            ->update(['status' => 'revoked']);
    }

    public function revokeFamily(string $familyId): void
    {
        Session::where('refresh_token_family_id', $familyId)
            ->whereNotIn('status', ['revoked', 'logged_out', 'expired'])
            ->update(['status' => 'revoked']);
    }

    public function updateLastActivity(string $id): void
    {
        Session::where('id', $id)->update(['last_activity_at' => now()]);
    }

    public function listActiveForUser(string $userId): array
    {
        return Session::where('user_id', $userId)
            ->where('status', 'active')
            ->orderByDesc('last_activity_at')
            ->get()
            ->all();
    }
}
