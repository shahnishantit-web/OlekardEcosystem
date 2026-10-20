<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

interface IdentityModuleContract
{
    /**
     * Resolve the authenticated actor from a Bearer JWT.
     * Returns null if the token is absent, expired, or invalid.
     * Does NOT perform RBAC; returns identity claims only.
     */
    public function resolveActorFromToken(string $bearerToken): ?AuthenticatedActor;

    /**
     * Resolve actor from a service-identity JWT.
     * Returns null for invalid/expired service tokens.
     */
    public function resolveServiceActor(string $bearerToken): ?ServiceActor;

    /**
     * Check whether a step-up token grants the requested scope.
     * Does NOT issue step-up; scope check only.
     */
    public function verifyStepUpScope(string $stepUpToken, string $requiredScope): bool;

    /**
     * Revoke a specific session by ID.
     * Used by admin flows in other modules (e.g., E04 admin tooling).
     */
    public function revokeSession(string $sessionId, string $revokedByActorId): void;

    // -------------------------------------------------------------------------
    // E02 extensions (ADR-E02-001) — implemented in E01 Phase 34 (T239–T244)
    // Until T239–T244 are complete, calls throw BadMethodCallException at runtime.
    // -------------------------------------------------------------------------

    /**
     * Fetch email and mobile_number for a registered user.
     * Called by E02's CreateProfileOnUserRegisteredListener at profile creation time.
     * Returns null if user_id is not found.
     */
    public function getUserContactInfo(string $userId): ?UserContactInfo;

    /**
     * Atomically update email and/or mobile_number on identity.users.
     * Called by E02's ContactChangeService after successful OTP verification.
     * Pass null for a field to leave it unchanged.
     *
     * @throws \App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException
     */
    public function updateUserContact(string $userId, ?string $email, ?string $mobile): void;
}
