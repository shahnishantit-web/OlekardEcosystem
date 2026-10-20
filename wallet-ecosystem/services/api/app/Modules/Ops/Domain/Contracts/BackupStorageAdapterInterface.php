<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Contracts;

interface BackupStorageAdapterInterface
{
    /** Store backup data and return a storage location reference. */
    public function store(array $metadata): string;

    /** Return true if the backup at the given location is readable and non-empty. */
    public function verify(string $location): bool;

    /** Remove the backup at the given location from storage. */
    public function delete(string $location): void;
}
