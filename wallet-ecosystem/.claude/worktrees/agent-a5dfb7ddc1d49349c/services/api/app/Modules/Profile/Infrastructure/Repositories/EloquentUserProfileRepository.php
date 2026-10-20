<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Repositories;

use App\Modules\Profile\Domain\Entities\UserProfile;
use App\Modules\Profile\Infrastructure\Models\UserProfileModel;
use Ramsey\Uuid\Uuid;

final class EloquentUserProfileRepository
{
    public function findByUserId(string $userId): ?UserProfileModel
    {
        return UserProfileModel::where('user_id', $userId)->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): UserProfileModel
    {
        $model = new UserProfileModel();
        $model->id       = (string) Uuid::uuid7();
        $model->user_id  = $attributes['user_id'];
        $model->email    = $attributes['email'] ?? null;
        $model->mobile_number = $attributes['mobile_number'] ?? null;
        $model->account_status = $attributes['account_status'] ?? 'active';
        $model->save();
        return $model->fresh();
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $userId, array $attributes): bool
    {
        return (bool) UserProfileModel::where('user_id', $userId)->update($attributes);
    }

    public function exists(string $userId): bool
    {
        return UserProfileModel::where('user_id', $userId)->exists();
    }

    /**
     * Return a domain entity for read-only consumption.
     * The caller must supply a pre-signed photo URL; the raw storage key is never embedded in the entity.
     */
    public function findEntityByUserId(string $userId, ?string $signedPhotoUrl = null): ?UserProfile
    {
        $model = $this->findByUserId($userId);
        if ($model === null) {
            return null;
        }

        return UserProfile::fromArray([
            'user_id'            => $model->user_id,
            'account_status'     => $model->account_status,
            'first_name'         => $model->first_name,
            'last_name'          => $model->last_name,
            'date_of_birth'      => $model->date_of_birth?->format('Y-m-d'),
            'gender'             => $model->gender,
            'address_street'     => $model->address_street,
            'address_city'       => $model->address_city,
            'address_region'     => $model->address_region,
            'address_postal_code' => $model->address_postal_code,
            'address_country'    => $model->address_country,
            'preferred_language' => $model->preferred_language,
            'email'              => $model->email,
            'mobile_number'      => $model->mobile_number,
            'profile_photo_url'  => $signedPhotoUrl,
            'created_at'         => $model->created_at->toIso8601String(),
            'updated_at'         => $model->updated_at->toIso8601String(),
        ]);
    }
}
