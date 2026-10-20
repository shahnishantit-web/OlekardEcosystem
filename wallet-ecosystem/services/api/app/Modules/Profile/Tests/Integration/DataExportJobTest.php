<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Infrastructure\Jobs\ProcessDataExportJob;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

// ---------------------------------------------------------------------------
// T118 — ProcessDataExportJob integration tests
// ---------------------------------------------------------------------------

describe('ProcessDataExportJob (T118)', function (): void {

    it('transitions export status to ready after job completes', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
            'first_name'     => 'Ada',
            'last_name'      => 'Lovelace',
        ]);

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
        expect($updated->status)->toBe('ready');
        expect($updated->download_key)->not->toBeNull();
        expect($updated->completed_at)->not->toBeNull();
    });

    it('emits Profile.User.DataExportReady to outbox after job completes', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

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

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.User.DataExportReady')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.User.DataExportReady must be written to outbox on job completion');

        $payload = json_decode($event->payload, true);
        expect($payload['user_id'])->toBe($userId);
        expect($payload['export_id'])->toBe($export->id);
        expect($payload['download_url'])->not->toBeNull();
    });

    it('exported JSON contains personal profile fields and contact information', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'         => $userId,
            'account_status'  => 'active',
            'email'           => 'ada@lovelace.example',
            'mobile_number'   => '+447700900001',
            'first_name'      => 'Ada',
            'last_name'       => 'Lovelace',
            'date_of_birth'   => '1815-12-10',
            'gender'          => 'F',
            'address_city'    => 'London',
            'address_country' => 'GB',
        ]);

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
        expect($updated->download_key)->not->toBeNull();

        // Retrieve the stored file and decode
        $content    = \Illuminate\Support\Facades\Storage::disk('local')->get($updated->download_key);
        $exportData = json_decode($content, true);

        // Personal profile fields
        expect($exportData['user_id'])->toBe($userId);
        expect($exportData['first_name'])->toBe('Ada');
        expect($exportData['last_name'])->toBe('Lovelace');
        expect($exportData['date_of_birth'])->toBe('1815-12-10');
        expect($exportData['gender'])->toBe('F');
        expect($exportData['address_city'])->toBe('London');
        expect($exportData['address_country'])->toBe('GB');
        expect($exportData['account_status'])->toBe('active');

        // Contact information — required by FR-E02-040
        expect($exportData['email'])->toBe('ada@lovelace.example', 'email (contact information) MUST be in export per FR-E02-040');
        expect($exportData['mobile_number'])->toBe('+447700900001', 'mobile_number (contact information) MUST be in export per FR-E02-040');

        expect($exportData)->toHaveKey('account_status_history');
        expect($exportData)->toHaveKey('exported_at');
    });

    it('exported JSON does NOT contain profile_photo_key', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

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

        $updated    = app(EloquentDataExportRepository::class)->findById($export->id);
        $content    = \Illuminate\Support\Facades\Storage::disk('local')->get($updated->download_key);
        $exportData = json_decode($content, true);

        expect($exportData)->not->toHaveKey('profile_photo_key', 'profile_photo_key MUST NOT appear in export');
    });

    it('exported JSON does NOT contain password, credential hashes, OTP values, or raw IP fields', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

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

        $updated    = app(EloquentDataExportRepository::class)->findById($export->id);
        $content    = \Illuminate\Support\Facades\Storage::disk('local')->get($updated->download_key);
        $exportData = json_decode($content, true);

        foreach (['password', 'password_hash', 'otp', 'otp_value', 'session_token', 'ip_address', 'raw_ip'] as $forbidden) {
            expect($exportData)->not->toHaveKey($forbidden, "{$forbidden} MUST NOT appear in export");
        }
    });

    it('marks export as failed and rethrows when storage fails', function (): void {
        $userId        = (string) Uuid::uuid7();
        $correlationId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $export = app(EloquentDataExportRepository::class)->create([
            'user_id'        => $userId,
            'status'         => 'processing',
            'requested_at'   => now(),
            'correlation_id' => $correlationId,
        ]);

        $failingStorage = Mockery::mock(ProfilePhotoStorageContract::class);
        $failingStorage->shouldReceive('storeExport')
            ->andThrow(new \RuntimeException('Disk full'));

        expect(function () use ($export, $userId, $correlationId, $failingStorage): void {
            $job = new ProcessDataExportJob($export->id, $userId, $correlationId);
            $job->handle(
                app(EloquentUserProfileRepository::class),
                app(EloquentAccountStatusHistoryRepository::class),
                app(EloquentDataExportRepository::class),
                $failingStorage,
                app(EventPublisherInterface::class),
                app(ConfigurationServiceInterface::class),
            );
        })->toThrow(\RuntimeException::class, 'Disk full');

        $updated = app(EloquentDataExportRepository::class)->findById($export->id);
        expect($updated->status)->toBe('failed');
        expect($updated->completed_at)->not->toBeNull();
    });

});
