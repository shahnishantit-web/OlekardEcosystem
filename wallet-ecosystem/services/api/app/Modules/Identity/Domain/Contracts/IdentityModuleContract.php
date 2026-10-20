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

    // -------------------------------------------------------------------------
    // E04 extensions — Roles, Permissions & Authorization
    // -------------------------------------------------------------------------

    /**
     * Resolves via role-permission model. Returns false on unknown actor, missing role,
     * or missing permission. MUST NOT throw.
     */
    public function hasPermission(string $actorId, string $permission): bool;

    // -------------------------------------------------------------------------
    // E09 extensions — Wallet-to-Wallet Transfer (Recipient Lookup)
    // -------------------------------------------------------------------------

    /**
     * Resolve a customer's user ID from their registered mobile number.
     * Returns null if no active customer account exists for the given number.
     * Called by Transfer's RecipientLookupService.
     */
    public function findCustomerIdByMobile(string $mobileNumber): ?string;

    // -------------------------------------------------------------------------
    // E08 extensions — Wallet Funding (Agent cash-in PIN verification)
    // -------------------------------------------------------------------------

    /**
     * Verify a customer or agent's transaction PIN.
     *
     * Returns true if the PIN matches; false otherwise.
     * The plaintext PIN MUST NOT be logged or persisted by this method.
     *
     * When $requireStepUp is true, the caller must also have an active step-up session
     * for the verification to succeed (E09 high-value transfers).
     * Callers that do not need step-up (E08 agent cash-in) pass the default false.
     *
     * Throws BadMethodCallException until E01/E03 implements it.
     */
    public function verifyTransactionPin(string $actorId, string $pin, bool $requireStepUp = false): bool;

    // -------------------------------------------------------------------------
    // E23 extensions — Audit & Compliance (SAR identity snapshot)
    // -------------------------------------------------------------------------

    /**
     * Return an identity snapshot for a given user for SAR generation.
     * Returns an array with keys: user_id, email, mobile_number, full_name, date_of_birth, nationality, id_document_type, id_document_number.
     * Returns null if the user is not found.
     * Throws BadMethodCallException until E01 implements it.
     */
    public function getIdentitySnapshot(string $userId): ?array;
}
