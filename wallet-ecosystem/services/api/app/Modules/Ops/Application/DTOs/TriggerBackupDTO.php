<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\DTOs;

use App\Modules\Ops\Domain\Enums\BackupType;

final readonly class TriggerBackupDTO
{
    public function __construct(
        public string   $idempotencyKey,
        public BackupType $type = BackupType::Full,
    ) {}
}
