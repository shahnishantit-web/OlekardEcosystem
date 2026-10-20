<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\Queries;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Application\DTOs\ProfileDTO;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;

final class GetProfileQuery
{
    public function __construct(
        private readonly EloquentUserProfileRepository $profiles,
        private readonly ProfilePhotoStorageContract $photoStorage,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /** @throws ProfileNotFoundException */
    public function execute(string $userId): ProfileDTO
    {
        $profile = $this->profiles->findByUserId($userId);

        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$userId}");
        }

        $photoUrl = null;
        if ($profile->profile_photo_key !== null) {
            $ttl      = (int) ($this->config->get('profile.photo.signed_url_ttl_seconds') ?? 3600);
            $photoUrl = $this->photoStorage->generateSignedUrl($profile->profile_photo_key, $ttl);
        }

        return new ProfileDTO(
            userId:            $profile->user_id,
            firstName:         $profile->first_name,
            lastName:          $profile->last_name,
            dateOfBirth:       $profile->date_of_birth?->format('Y-m-d'),
            gender:            $profile->gender,
            addressStreet:     $profile->address_street,
            addressCity:       $profile->address_city,
            addressRegion:     $profile->address_region,
            addressPostalCode: $profile->address_postal_code,
            addressCountry:    $profile->address_country,
            preferredLanguage: $profile->preferred_language,
            email:             $profile->email,
            mobileNumber:      $profile->mobile_number,
            profilePhotoUrl:   $photoUrl,
            accountStatus:     $profile->account_status,
            createdAt:         $profile->created_at->toIso8601String(),
            updatedAt:         $profile->updated_at->toIso8601String(),
        );
    }
}
