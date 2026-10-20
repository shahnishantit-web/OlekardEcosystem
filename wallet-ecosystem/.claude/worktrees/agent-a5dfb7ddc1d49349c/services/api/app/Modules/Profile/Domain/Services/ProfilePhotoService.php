<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Profile\Application\Commands\UploadProfilePhotoCommand;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Events\PhotoUpdatedEvent;
use App\Modules\Profile\Domain\Exceptions\FileTooLargeException;
use App\Modules\Profile\Domain\Exceptions\ImageDimensionsTooSmallException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Exceptions\UnsupportedMimeTypeException;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

final class ProfilePhotoService
{
    public function __construct(
        private readonly EloquentUserProfileRepository $profiles,
        private readonly ProfilePhotoStorageContract   $photoStorage,
        private readonly ConfigurationServiceInterface $config,
        private readonly EventPublisherInterface       $events,
        private readonly AuditServiceInterface         $audit,
    ) {}

    /**
     * Validate, store, and register a new profile photo.
     * Validation order: MIME type → file size → image dimensions.
     * Old photo is deleted after the new key is persisted.
     *
     * @throws UnsupportedMimeTypeException
     * @throws FileTooLargeException
     * @throws ImageDimensionsTooSmallException
     * @throws ProfileNotFoundException
     */
    public function upload(UploadProfilePhotoCommand $command): string
    {
        $file = $command->file;

        // --- MIME type validation ---
        $acceptedTypes = $this->config->get('profile.photo.accepted_mime_types');
        if ($acceptedTypes !== null) {
            $mimeType      = $file->getMimeType() ?? '';
            $accepted      = is_array($acceptedTypes) ? $acceptedTypes : explode(',', (string) $acceptedTypes);
            $accepted      = array_map('trim', $accepted);
            if ($mimeType === '' || ! in_array($mimeType, $accepted, true)) {
                throw new UnsupportedMimeTypeException(
                    "MIME type '{$mimeType}' is not accepted for profile photos."
                );
            }
        }

        // --- File size validation ---
        $maxBytes = $this->config->get('profile.photo.max_size_bytes');
        if ($maxBytes !== null && $file->getSize() > (int) $maxBytes) {
            throw new FileTooLargeException(
                "Uploaded file exceeds the maximum allowed size of {$maxBytes} bytes."
            );
        }

        // --- Image dimension validation (using Intervention Image) ---
        $minWidth  = $this->config->get('profile.photo.min_width_px');
        $minHeight = $this->config->get('profile.photo.min_height_px');

        if ($minWidth !== null || $minHeight !== null) {
            $manager = new ImageManager(new Driver());
            $image   = $manager->read($file->getPathname());
            $w       = $image->width();
            $h       = $image->height();

            if (($minWidth !== null && $w < (int) $minWidth) || ($minHeight !== null && $h < (int) $minHeight)) {
                throw new ImageDimensionsTooSmallException(
                    "Image dimensions {$w}x{$h} are below the required minimum."
                );
            }
        }

        // --- Load profile record (throws ProfileNotFoundException if absent) ---
        $profile = $this->profiles->findByUserId($command->userId);
        if ($profile === null) {
            throw new ProfileNotFoundException("Profile not found for user {$command->userId}");
        }

        $oldPhotoKey = $profile->profile_photo_key;

        // --- Persist photo to storage ---
        $result = $this->photoStorage->store($command->userId, $file);

        // --- Update profile record with new key ---
        $this->profiles->update($command->userId, ['profile_photo_key' => $result->storageKey]);

        // --- Clean up old photo (after new key is written — never orphan new photo) ---
        if ($oldPhotoKey !== null) {
            $this->photoStorage->delete($oldPhotoKey);
        }

        // --- Emit domain event (field names / metadata only; no raw key or URL in payload) ---
        $this->events->publish(new PhotoUpdatedEvent(
            userId:        $command->userId,
            actorId:       $command->actorId,
            correlationId: $command->correlationId,
            actorType:     $command->actorType,
        ));

        // --- Audit record ---
        $this->audit->record(new AuditEventData(
            action:        'profile.user.photo-updated',
            actorType:     $command->actorType,
            resourceType:  'UserProfile',
            correlationId: $command->correlationId,
            outcome:       'success',
            actorId:       $command->actorId,
            resourceId:    $command->userId,
            metadata:      ['had_previous_photo' => $oldPhotoKey !== null],
        ));

        // --- Return signed URL (raw key NEVER leaves this service boundary) ---
        $ttl = (int) ($this->config->get('profile.photo.signed_url_ttl_seconds') ?? 3600);
        return $this->photoStorage->generateSignedUrl($result->storageKey, $ttl);
    }
}
