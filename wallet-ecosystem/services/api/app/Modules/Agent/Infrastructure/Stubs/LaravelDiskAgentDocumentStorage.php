<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Stubs;

use App\Modules\Agent\Domain\Contracts\AgentDocumentStorageContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class LaravelDiskAgentDocumentStorage implements AgentDocumentStorageContract
{
    public function store(string $applicationId, UploadedFile $file): string
    {
        $disk = config('agent.document.storage_disk', 'agent-documents');

        $path = Storage::disk($disk)->putFile(
            "agent-applications/{$applicationId}/documents",
            $file
        );

        return (string) $path;
    }

    public function delete(string $storageReference): void
    {
        $disk = config('agent.document.storage_disk', 'agent-documents');
        Storage::disk($disk)->delete($storageReference);
    }
}
