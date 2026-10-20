<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Application\Commands\UpdatePersonalInfoCommand;
use App\Modules\Profile\Application\DTOs\ProfileDTO;
use App\Modules\Profile\Application\Queries\GetProfileQuery;
use App\Modules\Profile\Domain\Events\PersonalInfoUpdatedEvent;
use App\Modules\Profile\Domain\Exceptions\MinimumAgeViolationException;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Carbon\Carbon;

final class ProfileService
{
    // Columns the service is permitted to write — mirrors UserProfileModel::$fillable
    private const ALLOWED_COLUMNS = [
        'first_name', 'last_name', 'date_of_birth', 'gender',
        'address_street', 'address_city', 'address_region',
        'address_postal_code', 'address_country', 'preferred_language',
    ];

    public function __construct(
        private readonly EloquentUserProfileRepository $profiles,
        private readonly GetProfileQuery               $getProfile,
        private readonly ConfigurationServiceInterface $config,
        private readonly EventPublisherInterface       $events,
        private readonly AuditServiceInterface         $audit,
        private readonly ProfileMetrics                $metrics,
    ) {}

    /**
     * Persist personal info fields, emit event, write audit, increment metric.
     * Minimum age check is skipped when profile.minimum_user_age_years is null (TBD — constitution P2).
     *
     * @throws MinimumAgeViolationException
     */
    public function updatePersonalInfo(UpdatePersonalInfoCommand $command): ProfileDTO
    {
        $fields = $this->flattenAndFilterFields($command->fields);

        if ($fields === []) {
            // Nothing to update — still return the current profile
            return $this->getProfile->execute($command->targetUserId);
        }

        // Minimum age check — skip when config value is null (TBD per constitution P2)
        if (isset($fields['date_of_birth'])) {
            $minAge = $this->config->get('profile.minimum_user_age_years');
            if ($minAge !== null) {
                $age = Carbon::parse((string) $fields['date_of_birth'])->age;
                if ($age < (int) $minAge) {
                    throw new MinimumAgeViolationException(
                        'Date of birth does not meet the minimum age requirement.'
                    );
                }
            }
        }

        // Persist (query-builder update; form request + flattenAndFilterFields enforce column safety)
        $this->profiles->update($command->targetUserId, $fields);

        // Fetch updated profile for response
        $profile = $this->getProfile->execute($command->targetUserId);

        // Event — field names only; no PII values in payload
        $this->events->publish(new PersonalInfoUpdatedEvent(
            userId:        $command->targetUserId,
            actorId:       $command->actorId,
            correlationId: $command->correlationId,
            actorType:     $command->actorType,
            changedFields: array_keys($fields),
        ));

        // Audit
        $metadata = ['changed_fields' => array_keys($fields)];
        if ($command->subjectUserId !== null) {
            $metadata['subject_user_id'] = $command->subjectUserId;
        }

        $this->audit->record(new AuditEventData(
            action:        'profile.user.personal-info-updated',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->targetUserId,
            metadata:      $metadata,
        ));

        // Metric
        $this->metrics->updateSuccess($command->actorType, array_keys($fields));

        return $profile;
    }

    /**
     * Flatten nested address fields and intersect with allowed columns.
     * Defense-in-depth against any forbidden fields that escaped form-request validation.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function flattenAndFilterFields(array $fields): array
    {
        $flat = [];

        foreach ($fields as $key => $value) {
            if ($key === 'address' && is_array($value)) {
                foreach ($value as $addrKey => $addrVal) {
                    $flat["address_{$addrKey}"] = $addrVal;
                }
            } else {
                $flat[$key] = $value;
            }
        }

        // Keep only the explicitly allowed columns (mirror of $fillable)
        return array_intersect_key($flat, array_flip(self::ALLOWED_COLUMNS));
    }
}
