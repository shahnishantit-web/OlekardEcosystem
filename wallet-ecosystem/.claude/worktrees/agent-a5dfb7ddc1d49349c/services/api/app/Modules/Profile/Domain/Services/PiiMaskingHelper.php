<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Platform\Infrastructure\Security\PiiMasker;

// PiiMaskingHelper: delegates to Platform Foundation PiiMasker (T180 — found equivalent, no duplication)
final class PiiMaskingHelper
{
    public function maskEmail(string $email): string
    {
        return PiiMasker::maskEmail($email);
    }

    public function maskMobile(string $mobile): string
    {
        return PiiMasker::maskPhone($mobile);
    }
}
