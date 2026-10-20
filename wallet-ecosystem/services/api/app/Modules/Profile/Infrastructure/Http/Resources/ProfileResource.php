<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Resources;

use App\Modules\Profile\Application\DTOs\ProfileDTO;
use Illuminate\Http\Resources\Json\JsonResource;

// profile_photo_key MUST NOT appear in this resource (ADR-E02-002; spec §5).
final class ProfileResource extends JsonResource
{
    public function __construct(private readonly ProfileDTO $profile) {}

    public function toArray($request): array
    {
        return [
            'success' => true,
            'data'    => [
                'user_id'          => $this->profile->userId,
                'first_name'       => $this->profile->firstName,
                'last_name'        => $this->profile->lastName,
                'date_of_birth'    => $this->profile->dateOfBirth,
                'gender'           => $this->profile->gender,
                'address'          => [
                    'street'      => $this->profile->addressStreet,
                    'city'        => $this->profile->addressCity,
                    'region'      => $this->profile->addressRegion,
                    'postal_code' => $this->profile->addressPostalCode,
                    'country'     => $this->profile->addressCountry,
                ],
                'preferred_language' => $this->profile->preferredLanguage,
                'email'              => $this->profile->email,
                'mobile_number'      => $this->profile->mobileNumber,
                'profile_photo_url'  => $this->profile->profilePhotoUrl,
                'account_status'     => $this->profile->accountStatus,
                'created_at'         => $this->profile->createdAt,
                'updated_at'         => $this->profile->updatedAt,
            ],
        ];
    }
}
