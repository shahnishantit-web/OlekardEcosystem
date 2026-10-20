<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;

final readonly class PaymentAttemptResult
{
    public function __construct(
        public PaymentAttemptStatus $status,
        public int                  $feeMinor = 0,
        public ?string              $providerReferenceId = null,
        public ?string              $nextActionType = null,
        public ?array               $nextActionData = null,
        public ?string              $walletHoldId = null,
        public ?string              $ledgerJournalId = null,
    ) {
    }
}
