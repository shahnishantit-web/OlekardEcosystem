<?php

declare(strict_types=1);

use App\Modules\Profile\Infrastructure\Models\UserProfileModel;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('Profile schema migration', function (): void {

    it('creates the profile.user_profiles table with all required columns', function (): void {
        $columns = Schema::connection('profile')->getColumnListing('profile.user_profiles');

        expect($columns)->toContain('id');
        expect($columns)->toContain('user_id');
        expect($columns)->toContain('first_name');
        expect($columns)->toContain('last_name');
        expect($columns)->toContain('date_of_birth');
        expect($columns)->toContain('gender');
        expect($columns)->toContain('address_street');
        expect($columns)->toContain('address_city');
        expect($columns)->toContain('address_region');
        expect($columns)->toContain('address_postal_code');
        expect($columns)->toContain('address_country');
        expect($columns)->toContain('preferred_language');
        expect($columns)->toContain('email');
        expect($columns)->toContain('mobile_number');
        expect($columns)->toContain('profile_photo_key');
        expect($columns)->toContain('account_status');
        expect($columns)->toContain('pending_email');
        expect($columns)->toContain('pending_email_challenge_id');
        expect($columns)->toContain('pending_mobile');
        expect($columns)->toContain('pending_mobile_challenge_id');
        expect($columns)->toContain('created_at');
        expect($columns)->toContain('updated_at');
    });

    it('enforces UNIQUE constraint on user_id in user_profiles', function (): void {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();

        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'             => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'        => $userId,
            'account_status' => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        expect(fn () => DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'             => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'        => $userId,
            'account_status' => 'active',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('defaults account_status to active', function (): void {
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();
        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'         => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'user_id'    => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::connection('profile')->table('profile.user_profiles')
            ->where('user_id', $userId)->first();

        expect($row->account_status)->toBe('active');
    });

    it('creates the profile.account_status_history table with no updated_at column', function (): void {
        $columns = Schema::connection('profile')->getColumnListing('profile.account_status_history');

        expect($columns)->toContain('id');
        expect($columns)->toContain('user_id');
        expect($columns)->toContain('from_status');
        expect($columns)->toContain('to_status');
        expect($columns)->toContain('reason');
        expect($columns)->toContain('actor_id');
        expect($columns)->toContain('actor_type');
        expect($columns)->toContain('closure_obligations');
        expect($columns)->toContain('correlation_id');
        expect($columns)->toContain('created_at');
        expect($columns)->not->toContain('updated_at');
    });

    it('creates the profile.data_exports table with all required columns', function (): void {
        $columns = Schema::connection('profile')->getColumnListing('profile.data_exports');

        expect($columns)->toContain('id');
        expect($columns)->toContain('user_id');
        expect($columns)->toContain('status');
        expect($columns)->toContain('download_key');
        expect($columns)->toContain('expires_at');
        expect($columns)->toContain('requested_at');
        expect($columns)->toContain('completed_at');
        expect($columns)->toContain('correlation_id');
        expect($columns)->toContain('created_at');
        expect($columns)->toContain('updated_at');
    });

    it('seeds profile configuration defaults idempotently', function (): void {
        $expectedKeys = [
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
            'profile.rate_limit.admin_per_minute', // T184: admin-specific throttle config key
        ];

        foreach ($expectedKeys as $key) {
            $row = DB::connection('platform')->table('platform.configuration_values')
                ->where('config_key', $key)->first();

            expect($row)->not->toBeNull("Config key '{$key}' was not seeded");
            expect((bool) $row->is_tbd)->toBeTrue("Config key '{$key}' should be is_tbd = true");
        }

        // Second call is idempotent — no exception thrown
        $migration = require __DIR__ . '/../../Infrastructure/Migrations/2026_08_27_004000_seed_profile_configuration_defaults.php';
        $migration->up();

        $count = DB::connection('platform')->table('platform.configuration_values')
            ->whereIn('config_key', $expectedKeys)->count();

        expect($count)->toBe(count($expectedKeys));
    });

    it('resolves the profile named connection', function (): void {
        $result = DB::connection('profile')->selectOne('SELECT 1 AS one');
        expect($result->one)->toBe(1);
    });

    it('executes UserProfileModel::query() on the profile connection', function (): void {
        $query = UserProfileModel::query();
        expect($query->getModel()->getConnectionName())->toBe('profile');
    });

    it('can create and retrieve a profile via repository', function (): void {
        $repo   = app(EloquentUserProfileRepository::class);
        $userId = (string) \Ramsey\Uuid\Uuid::uuid7();

        $profile = $repo->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        expect($profile->user_id)->toBe($userId);
        expect($profile->account_status)->toBe('active');

        $found = $repo->findByUserId($userId);
        expect($found)->not->toBeNull();
        expect($found->id)->toBe($profile->id);
    });
});
