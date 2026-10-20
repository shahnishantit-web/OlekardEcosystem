<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Adapters;

use App\Modules\Ops\Domain\Contracts\BackupStorageAdapterInterface;
use Illuminate\Support\Facades\Storage;

final class LocalDiskBackupStorageAdapter implements BackupStorageAdapterInterface
{
    private const DISK = 'local';

    public function store(array $data): string
    {
        $path     = 'backups/' . date('Y/m/d') . '/' . uniqid('backup_', true) . '.json';
        $contents = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    public function verify(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
