<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Contracts;

use Illuminate\Http\UploadedFile;

interface EvidenceStorageContract
{
    public function store(string $disputeId, string $evidenceId, UploadedFile $file): string;

    public function generateSignedUrl(string $fileKey, int $ttlMinutes = 15): string;
}
