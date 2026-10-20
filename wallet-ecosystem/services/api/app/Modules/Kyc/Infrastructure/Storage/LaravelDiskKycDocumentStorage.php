<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Storage;

use App\Modules\Kyc\Domain\Contracts\KycDocumentStorageContract;
use App\Modules\Kyc\Domain\Exceptions\KycDocumentStorageException;
use App\Modules\Kyc\Domain\ValueObjects\KycStorageResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class LaravelDiskKycDocumentStorage implements KycDocumentStorageContract
{
    private const DISK = 'kyc-documents';

    public function store(string $caseId, mixed $file): KycStorageResult
    {
        /** @var UploadedFile $file */
        try {
            $ext        = $file->getClientOriginalExtension();
            $storageKey = "kyc-documents/{$caseId}/" . uniqid('doc_', true) . '.' . $ext;

            Storage::disk(self::DISK)->put($storageKey, $file->getContent());

            return new KycStorageResult(
                storageKey: $storageKey,
                sizeBytes:  (int) $file->getSize(),
                mimeType:   $file->getMimeType() ?? 'application/octet-stream',
            );
        } catch (\Throwable $e) {
            throw new KycDocumentStorageException(
                'Failed to store KYC document: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    public function delete(string $storageKey): void
    {
        if (Storage::disk(self::DISK)->exists($storageKey)) {
            Storage::disk(self::DISK)->delete($storageKey);
        }
    }

    public function generateSignedUrl(string $storageKey, int $expiresInSeconds): string
    {
        return URL::temporarySignedRoute(
            'kyc.document.serve',
            now()->addSeconds($expiresInSeconds),
            ['key' => $storageKey],
        );
    }
}
