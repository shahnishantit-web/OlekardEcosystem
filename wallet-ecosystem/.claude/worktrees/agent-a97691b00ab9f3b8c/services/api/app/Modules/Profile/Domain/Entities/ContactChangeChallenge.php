<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Entities;

/**
 * Read-only domain entity representing an in-progress contact-change challenge.
 * Derived from profile.user_profiles.pending_email / pending_mobile columns.
 * No Eloquent dependency.
 */
final readonly class ContactChangeChallenge
{
    public function __construct(
        public string  $challengeId,
        public string  $type,
        public string  $pendingContact,
        public string  $expiresAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            challengeId:    (string) $data['challenge_id'],
            type:           (string) $data['type'],
            pendingContact: (string) $data['pending_contact'],
            expiresAt:      (string) $data['expires_at'],
        );
    }
}
