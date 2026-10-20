<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Infrastructure\Jobs\ProcessDataExportJob;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

// ---------------------------------------------------------------------------
// T156 — Data export security: sensitive field exclusion
// ---------------------------------------------------------------------------

describe('Data export security — sensitive field exclusion (T156)', function (): void {

    /**
     * Run the ProcessDataExportJob synchronously and return the decoded export JSON.
     *
     * Uses a Mockery mock to capture the content passed to ProfilePhotoStorageContract::storeExport()
     * because the contract does not expose a getExportContent() read-back method.
     */
    function runExportJob(string $userId): array
    {
        $correlationId  = (string) Uuid::uuid7();
        $capturedContent = null;

        // Capture the serialised export payload that the job passes to storeExport().
        $capturingStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $capturingStorage
            ->shouldReceive('storeExport')
            ->once()
            ->andReturnUsing(function (string $uid, string $content) use (&$capturedContent): string {
                $capturedContent = $content;
                return "profile-exports/{$uid}/test-export.json";
            });
        app()->instance(ProfilePhotoStorageContract::class, $capturingStorage);

        $export = app(EloquentDataExportRepository::class)->create([
            'user_id'        => $userId,
            'status'         => 'processing',
            'requested_at'   => now(),
            'correlation_id' => $correlationId,
        ]);

        $job = new ProcessDataExportJob($export->id, $userId, $correlationId);
        $job->handle(
            app(EloquentUserProfileRepository::class),
            app(EloquentAccountStatusHistoryRepository::class),
            app(EloquentDataExportRepository::class),
            app(ProfilePhotoStorageContract::class),
            app(EventPublisherInterface::class),
            app(ConfigurationServiceInterface::class),
        );

        $updated = app(EloquentDataExportRepository::class)->findById($export->id);
        expect($updated->status)->toBe('ready', 'Export job must complete successfully');
        expect($capturedContent)->not->toBeNull('storeExport() must have been called with the export content');

        return json_decode($capturedContent, true, 512, JSON_THROW_ON_ERROR);
    }

    it('profile_photo_key is absent from the export JSON', function (): void {
        $userId = (string) Uuid::uuid7();

        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'                => (string) Uuid::uuid7(),
            'user_id'           => $userId,
            'account_status'    => 'active',
            'profile_photo_key' => "users/{$userId}/private-photo.jpg",
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $exportData = runExportJob($userId);
        $exportJson = json_encode($exportData);

        expect($exportJson)->not->toContain('profile_photo_key', 'profile_photo_key must be excluded from data export');
        expect($exportJson)->not->toContain('private-photo.jpg', 'Raw storage object key path must not appear in export');
    });

    it('credential hashes (password_hash) are absent from the export JSON', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $exportData = runExportJob($userId);
        $exportJson = json_encode($exportData);

        expect($exportJson)->not->toContain('password_hash', 'Password hashes must never appear in data export');
        expect($exportJson)->not->toContain('pin_hash', 'PIN hashes must never appear in data export');
        expect($exportJson)->not->toContain('credential_hash', 'Credential hashes must never appear in data export');
    });

    it('OTP values and challenge IDs are absent from the export JSON', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $exportData = runExportJob($userId);
        $exportJson = json_encode($exportData);

        expect($exportJson)->not->toContain('otp_value', 'OTP values must never appear in data export');
        expect($exportJson)->not->toContain('otp_code', 'OTP codes must never appear in data export');
        expect($exportJson)->not->toContain('challenge_id', 'OTP challenge IDs must not appear in data export');
        expect($exportJson)->not->toContain('pending_email_challenge_id', 'Pending email challenge IDs must not appear in data export');
        expect($exportJson)->not->toContain('pending_mobile_challenge_id', 'Pending mobile challenge IDs must not appear in data export');
    });

    it('session tokens are absent from the export JSON', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $exportData = runExportJob($userId);
        $exportJson = json_encode($exportData);

        expect($exportJson)->not->toContain('session_token', 'Session tokens must not appear in data export');
        expect($exportJson)->not->toContain('access_token', 'Access tokens must not appear in data export');
        expect($exportJson)->not->toContain('refresh_token', 'Refresh tokens must not appear in data export');
    });

    it('export contains the expected user-visible personal data fields', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'first_name'     => 'ExportTest',
            'last_name'      => 'User',
            'email'          => 'export@example.com',
        ]);

        $exportData = runExportJob($userId);

        // These fields are user-visible personal data and must be included
        expect($exportData)->toHaveKey('user_id');
        expect($exportData)->toHaveKey('first_name');
        expect($exportData)->toHaveKey('last_name');
        expect($exportData)->toHaveKey('email');
        expect($exportData)->toHaveKey('account_status');
        expect($exportData)->toHaveKey('account_status_history');
        expect($exportData)->toHaveKey('exported_at');

        expect($exportData['user_id'])->toBe($userId);
        expect($exportData['first_name'])->toBe('ExportTest');
    });

    it('export does not contain raw IP addresses', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $exportData = runExportJob($userId);
        $exportJson = json_encode($exportData);

        expect($exportJson)->not->toContain('ip_address', 'Raw IP addresses must not appear in data export');
        expect($exportJson)->not->toContain('last_login_ip', 'Login IP addresses must not appear in data export');
    });
});
