<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Commands;

/**
 * Command carrying validated personal info fields to persist.
 * Fields are keyed by request field names (address is nested under 'address').
 * ProfileService::updatePersonalInfo() flattens address before persisting.
 *
 * @property array<string, mixed> $fields Validated fields from UpdatePersonalInfoRequest
 */
final readonly class UpdatePersonalInfoCommand
{
    /**
     * @param array<string, mixed> $fields Validated personal info fields (may include nested address)
     * @param string|null $subjectUserId Admin-path only: the target user; actorId is the admin's userId
     */
    public function __construct(
        public string  $targetUserId,
        public string  $actorId,
        public string  $actorType,
        public array   $fields,
        public string  $correlationId,
        public ?string $subjectUserId = null,
    ) {}
}
