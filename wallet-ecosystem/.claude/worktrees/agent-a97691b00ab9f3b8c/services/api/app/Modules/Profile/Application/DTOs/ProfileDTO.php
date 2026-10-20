<?php

declare(strict_types=1);

namespace App\Modules\Profile\Application\DTOs;

final readonly class ProfileDTO
{
    public function __construct(
        public string  $userId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $dateOfBirth,
        public ?string $gender,
        public ?string $addressStreet,
        public ?string $addressCity,
        public ?string $addressRegion,
        public ?string $addressPostalCode,
        public ?string $addressCountry,
        public ?string $preferredLanguage,
        public ?string $email,
        public ?string $mobileNumber,
        public ?string $profilePhotoUrl, // signed URL or null; raw key never exposed
        public string  $accountStatus,
        public string  $createdAt,
        public string  $updatedAt,
    ) {}
}
