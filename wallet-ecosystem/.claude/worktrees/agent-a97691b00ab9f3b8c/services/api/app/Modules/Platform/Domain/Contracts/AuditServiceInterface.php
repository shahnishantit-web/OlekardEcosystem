<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

use App\Modules\Platform\Domain\ValueObjects\AuditEventData;

interface AuditServiceInterface
{
    public function record(AuditEventData $event): void;
}
