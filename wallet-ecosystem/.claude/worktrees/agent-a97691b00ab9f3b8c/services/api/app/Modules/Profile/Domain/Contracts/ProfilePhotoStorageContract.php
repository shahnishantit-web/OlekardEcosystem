<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Contracts;

use App\Modules\Profile\Domain\Exceptions\PhotoStorageException;

interface ProfilePhotoStorageContract
{
    /**
     * Persist an uploaded photo and return storage metadata.
     *
     * @throws PhotoStorageException on write failure
     */
    public function store(string $userId, mixed $file): PhotoStorageResult;

    /**
     * Delete a stored photo by its internal key.
     * Silent no-op if key does not exist.
     */
    public function delete(string $storageKey): void;

    /**
     * Generate a time-limited signed URL for a stored photo.
     * The returned URL MUST NOT embed raw storage credentials or bucket paths.
     */
    public function generateSignedUrl(string $storageKey, int $expiresInSeconds): string;

    /**
     * Persist raw string content (e.g., a JSON export) and return the storage key.
     * The key can later be passed to generateSignedUrl() for time-limited download access.
     *
     * @throws PhotoStorageException on write failure
     */
    public function storeExport(string $userId, string $content): string;
}
