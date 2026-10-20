<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UserProfileModel extends Model
{
    use HasUuids;

    protected $connection = 'profile';

    protected $table = 'profile.user_profiles';

    // Only personal-info fields are fillable. System-managed fields are explicitly guarded.
    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'address_street',
        'address_city',
        'address_region',
        'address_postal_code',
        'address_country',
        'preferred_language',
    ];

    // System-managed / contact fields set only through dedicated service methods
    protected $guarded = [
        'id',
        'user_id',
        'account_status',
        'email',
        'mobile_number',
        'profile_photo_key',
        'pending_email',
        'pending_email_challenge_id',
        'last_completed_email_challenge_id',
        'pending_mobile',
        'pending_mobile_challenge_id',
        'last_completed_mobile_challenge_id',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'created_at'    => 'immutable_datetime',
        'updated_at'    => 'immutable_datetime',
    ];

    // CHAR(5) columns in PostgreSQL right-pad with spaces; trim on read so 'en' doesn't become 'en   '.
    protected function preferredLanguage(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null ? rtrim($value) : null,
        );
    }
}
