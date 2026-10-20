<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Exceptions;

use RuntimeException;

final class RecipientNotFoundException extends RuntimeException {}
