<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Contracts;

use App\Modules\Wallet\Domain\ValueObjects\WalletCaptureEvidence;
use App\Modules\Wallet\Domain\ValueObjects\WalletCaptureRequest;

/** Internal committed-capture boundary. Operational use is disabled pending AD-08. */
interface WalletCaptureContract
{
    public function captureForPayment(WalletCaptureRequest $request): WalletCaptureEvidence;

    public function committedCaptureForHold(string $holdId): ?WalletCaptureEvidence;
}
