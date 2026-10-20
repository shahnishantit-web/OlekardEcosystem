<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class OtpDeliveryException extends RuntimeException {}
