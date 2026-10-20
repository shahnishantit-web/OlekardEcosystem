<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Contracts;

use Illuminate\Http\UploadedFile;

interface AgentDocumentStorageContract
{
    public function store(string $applicationId, UploadedFile $file): string;

    public function delete(string $storageReference): void;
}
