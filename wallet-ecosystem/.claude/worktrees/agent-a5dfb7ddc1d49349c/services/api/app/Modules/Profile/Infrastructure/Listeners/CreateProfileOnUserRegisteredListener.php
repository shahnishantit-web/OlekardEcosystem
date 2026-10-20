<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Listeners;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Domain\Events\ProfileCreatedEvent;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class CreateProfileOnUserRegisteredListener
{
    public function __construct(
        private readonly EloquentUserProfileRepository $profiles,
        private readonly IdentityModuleContract $identity,
        private readonly EventPublisherInterface $events,
        private readonly AuditServiceInterface $audit,
    ) {}

    /**
     * Consume Identity.User.Registered outbox event (dispatched by InProcessDriver).
     * Idempotent: insertOrIgnore equivalent via exists() check before create().
     *
     * Plan deviation (T255): The implementation plan listed a separate CreateProfileCommand.php
     * in Application/Commands/. That command object was omitted because this listener is the
     * sole caller; there is no second entry point (no HTTP endpoint, no CLI command, no test
     * helper) that would benefit from a reusable command envelope. The profile creation logic
     * lives inline here for clarity. See ADR-E02-001 addendum for the recorded decision.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $userId = $payload['user_id'] ?? null;
        if ($userId === null) {
            Log::warning('CreateProfileOnUserRegisteredListener: missing user_id in payload');
            return;
        }

        $correlationId = $payload['correlation_id'] ?? (string) Uuid::uuid7();
        $actorType     = $payload['actor_type'] ?? 'CUSTOMER';

        // Idempotency: skip if profile already exists (duplicate event)
        if ($this->profiles->exists($userId)) {
            return;
        }

        // getUserContactInfo() throws BadMethodCallException until E01 T239–T244 complete (ADR-E02-001)
        $contactInfo = $this->identity->getUserContactInfo($userId);

        DB::connection('platform')->transaction(function () use ($userId, $correlationId, $actorType, $contactInfo): void {
            $this->profiles->create([
                'user_id'        => $userId,
                'email'          => $contactInfo?->email,
                'mobile_number'  => $contactInfo?->mobileNumber,
                'account_status' => 'active',
            ]);

            $this->events->publish(new ProfileCreatedEvent($userId, $correlationId, $actorType));

            // actorId = zero-UUID SYSTEM: profile creation is system-generated from event listener (plan §Audit Plan)
            $this->audit->record(new AuditEventData(
                action:        'profile.user.profile-created',
                actorType:     'SYSTEM',
                resourceType:  'UserProfile',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       '00000000-0000-0000-0000-000000000000',
                resourceId:    $userId,
                metadata:      ['subject_user_id' => $userId, 'trigger_actor_type' => $actorType],
            ));
        });
    }
}
