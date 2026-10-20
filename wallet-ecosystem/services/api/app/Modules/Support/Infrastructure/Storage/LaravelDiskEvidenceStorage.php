<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Storage;

use App\Modules\Support\Domain\Contracts\EvidenceStorageContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class LaravelDiskEvidenceStorage implements EvidenceStorageContract
{
    private const DISK = 'support-evidence';

    public function store(string $disputeId, string $evidenceId, UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $fileKey = "support-evidence/{$disputeId}/{$evidenceId}.{$extension}";

        Storage::disk(self::DISK)->putFileAs(
            "support-evidence/{$disputeId}",
            $file,
            "{$evidenceId}.{$extension}"
        );

        return $fileKey;
    }

    public function generateSignedUrl(string $fileKey, int $ttlMinutes = 15): string
    {
        return Storage::disk(self::DISK)->temporaryUrl(
            $fileKey,
            now()->addMinutes($ttlMinutes)
        );
    }
}
