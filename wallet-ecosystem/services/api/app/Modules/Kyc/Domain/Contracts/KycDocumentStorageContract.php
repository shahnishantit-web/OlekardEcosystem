<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Domain\Contracts;

use App\Modules\Kyc\Domain\Exceptions\KycDocumentStorageException;
use App\Modules\Kyc\Domain\ValueObjects\KycStorageResult;

interface KycDocumentStorageContract
{
    /**
     * @throws KycDocumentStorageException
     */
    public function store(string $caseId, mixed $file): KycStorageResult;

    public function delete(string $storageKey): void;

    public function generateSignedUrl(string $storageKey, int $expiresInSeconds): string;
}
