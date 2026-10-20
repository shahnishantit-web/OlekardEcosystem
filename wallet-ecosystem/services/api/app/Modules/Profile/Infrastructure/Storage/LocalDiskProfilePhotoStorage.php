<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Storage;

use App\Modules\Profile\Domain\Contracts\PhotoStorageResult;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Exceptions\PhotoStorageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Facades\Image;

final class LocalDiskProfilePhotoStorage implements ProfilePhotoStorageContract
{
    public function store(string $userId, mixed $file): PhotoStorageResult
    {
        /** @var UploadedFile $file */
        try {
            $image = Image::read($file->getPathname());
            $widthPx  = $image->width();
            $heightPx = $image->height();

            $storageKey = "profile-photos/{$userId}/" . uniqid('photo_', true) . '.' . $file->getClientOriginalExtension();

            Storage::disk('local')->put($storageKey, $file->getContent());

            return new PhotoStorageResult(
                storageKey: $storageKey,
                sizeBytes:  $file->getSize(),
                mimeType:   $file->getMimeType() ?? 'application/octet-stream',
                widthPx:    $widthPx,
                heightPx:   $heightPx,
            );
        } catch (\Throwable $e) {
            throw new PhotoStorageException('Failed to store profile photo: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $storageKey): void
    {
        if (Storage::disk('local')->exists($storageKey)) {
            Storage::disk('local')->delete($storageKey);
        }
    }

    public function generateSignedUrl(string $storageKey, int $expiresInSeconds): string
    {
        return URL::temporarySignedRoute(
            'profile.photo.serve',
            now()->addSeconds($expiresInSeconds),
            ['key' => $storageKey],
        );
    }

    public function storeExport(string $userId, string $content): string
    {
        try {
            $storageKey = "profile-exports/{$userId}/" . uniqid('export_', true) . '.json';
            Storage::disk('local')->put($storageKey, $content);
            return $storageKey;
        } catch (\Throwable $e) {
            throw new PhotoStorageException('Failed to store data export: ' . $e->getMessage(), 0, $e);
        }
    }
}
