<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Events;

/**
 * @deprecated Use CommissionCalculatedEvent. Retained as alias during migration.
 */
final class CommissionAccruedEvent extends CommissionCalculatedEvent {}
