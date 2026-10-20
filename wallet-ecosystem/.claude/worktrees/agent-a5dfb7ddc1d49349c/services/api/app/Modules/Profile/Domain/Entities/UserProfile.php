<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Entities;

/**
 * Read-only domain entity representing a user profile.
 * No Eloquent dependency — constructed from repository data by the application layer.
 * profilePhotoUrl is a caller-supplied signed URL; the raw storage key is never included.
 */
final readonly class UserProfile
{
    public function __construct(
        public string  $userId,
        public string  $accountStatus,
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
        public ?string $profilePhotoUrl,
        public string  $createdAt,
        public string  $updatedAt,
    ) {}

    /**
     * Construct from a plain associative array.
     * Callers are responsible for supplying a pre-signed `profile_photo_url`
     * (never the raw `profile_photo_key`).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            userId:            (string) $data['user_id'],
            accountStatus:     (string) $data['account_status'],
            firstName:         isset($data['first_name'])          ? (string) $data['first_name']          : null,
            lastName:          isset($data['last_name'])           ? (string) $data['last_name']           : null,
            dateOfBirth:       isset($data['date_of_birth'])       ? (string) $data['date_of_birth']       : null,
            gender:            isset($data['gender'])              ? (string) $data['gender']              : null,
            addressStreet:     isset($data['address_street'])      ? (string) $data['address_street']      : null,
            addressCity:       isset($data['address_city'])        ? (string) $data['address_city']        : null,
            addressRegion:     isset($data['address_region'])      ? (string) $data['address_region']      : null,
            addressPostalCode: isset($data['address_postal_code']) ? (string) $data['address_postal_code'] : null,
            addressCountry:    isset($data['address_country'])     ? (string) $data['address_country']     : null,
            preferredLanguage: isset($data['preferred_language'])  ? (string) $data['preferred_language']  : null,
            email:             isset($data['email'])               ? (string) $data['email']               : null,
            mobileNumber:      isset($data['mobile_number'])       ? (string) $data['mobile_number']       : null,
            profilePhotoUrl:   isset($data['profile_photo_url'])   ? (string) $data['profile_photo_url']   : null,
            createdAt:         (string) $data['created_at'],
            updatedAt:         (string) $data['updated_at'],
        );
    }
}
