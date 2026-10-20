<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// T018 + T177 merged: seeds all profile config keys including rate-limit config
return new class extends Migration
{
    protected $connection = 'platform';

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    private function tbdRow(string $key, string $valueType, string $description): array
    {
        return [
            'id'              => (string) Str::uuid(),
            'config_key'      => $key,
            'config_value'    => null,
            'value_type'      => $valueType,
            'status'          => 'active',
            'version'         => 1,
            'is_tbd'          => true,
            'description'     => $description,
            'effective_from'  => null,
            'effective_until' => null,
            'created_at'      => $this->now(),
            'updated_at'      => $this->now(),
        ];
    }

    public function up(): void
    {
        // insertOrIgnore = ON CONFLICT DO NOTHING; never overwrites existing values; idempotent
        DB::connection('platform')->table('platform.configuration_values')->insertOrIgnore([
            $this->tbdRow(
                'profile.minimum_user_age_years',
                'number',
                'Minimum age in years required for profile date_of_birth; null = check not active'
            ),
            $this->tbdRow(
                'profile.photo.max_size_bytes',
                'number',
                'Maximum allowed profile photo file size in bytes'
            ),
            $this->tbdRow(
                'profile.photo.accepted_mime_types',
                'string',
                'Comma-separated list of accepted MIME types for profile photos (e.g., image/jpeg,image/png)'
            ),
            $this->tbdRow(
                'profile.photo.min_width_px',
                'number',
                'Minimum width in pixels for uploaded profile photos'
            ),
            $this->tbdRow(
                'profile.photo.min_height_px',
                'number',
                'Minimum height in pixels for uploaded profile photos'
            ),
            $this->tbdRow(
                'profile.photo.signed_url_ttl_seconds',
                'number',
                'Time-to-live in seconds for signed profile photo URLs'
            ),
            $this->tbdRow(
                'profile.contact_change.otp_rate_limit_per_hour',
                'number',
                'Maximum OTP-triggered contact change attempts per user per hour'
            ),
            $this->tbdRow(
                'profile.maker_checker.enabled',
                'boolean',
                'Enable maker-checker approval workflow for privileged profile operations'
            ),
            // T177: rate-limit config keys (sourced at runtime by ProfileServiceProvider; fallback ?? 60 / ?? 20)
            $this->tbdRow(
                'profile.rate_limit.user_per_minute',
                'number',
                'Profile mutation rate limit per user per minute; null = use ?? 60 fallback'
            ),
            $this->tbdRow(
                'profile.rate_limit.photo_per_minute',
                'number',
                'Photo upload rate limit per user per minute; null = use ?? 20 fallback'
            ),
        ]);
    }

    public function down(): void
    {
        DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', [
                'profile.minimum_user_age_years',
                'profile.photo.max_size_bytes',
                'profile.photo.accepted_mime_types',
                'profile.photo.min_width_px',
                'profile.photo.min_height_px',
                'profile.photo.signed_url_ttl_seconds',
                'profile.contact_change.otp_rate_limit_per_hour',
                'profile.maker_checker.enabled',
                'profile.rate_limit.user_per_minute',
                'profile.rate_limit.photo_per_minute',
            ])
            ->where('version', 1)
            ->delete();
    }
};
