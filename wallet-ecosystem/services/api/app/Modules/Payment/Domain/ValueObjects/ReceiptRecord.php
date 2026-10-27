<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;

final readonly class ReceiptRecord
{
    /**
     * @param array<int, array{amount_minor: int, status: string, confirmed_at: string|null}> $refundSummary
     */
    public function __construct(
        public string $paymentReference,
        public ?string $providerLabel,
        public int    $originalAmountMinor,
        public string $currencyCode,
        public int    $feeAmountMinor,
        public int    $totalChargedMinor,
        public string $paymentDate,
        public string $purposeSummary,
        public string $receiptStatus,
        public array  $refundSummary,
    ) {
    }

    /**
     * @param array<int, \App\Modules\Payment\Infrastructure\Models\PaymentRefundModel> $refunds
     */
    public static function fromModels(
        PaymentIntentModel  $intent,
        PaymentAttemptModel $attempt,
        array               $refunds,
    ): self {
        $feeAmountMinor      = (int) ($attempt->fee_minor ?? 0);
        $originalAmountMinor = (int) $intent->amount_minor;
        $totalChargedMinor   = $originalAmountMinor + $feeAmountMinor;

        $providerLabel = ucwords(str_replace(['_', '-'], ' ', $attempt->provider_code));

        $refundSummary = array_map(
            static fn ($refund): array => [
                'amount_minor' => (int) $refund->amount_minor,
                'status'       => (string) $refund->status->value,
                'confirmed_at' => $refund->confirmed_at?->toIso8601String(),
            ],
            $refunds,
        );

        return new self(
            paymentReference:    (string) $intent->id,
            providerLabel:       $providerLabel,
            originalAmountMinor: $originalAmountMinor,
            currencyCode:        (string) $intent->currency_code,
            feeAmountMinor:      $feeAmountMinor,
            totalChargedMinor:   $totalChargedMinor,
            paymentDate:         $attempt->confirmed_at?->toIso8601String() ?? now()->toIso8601String(),
            purposeSummary:      ucwords(str_replace('_', ' ', (string) $intent->purpose_type)),
            receiptStatus:       'confirmed',
            refundSummary:       $refundSummary,
        );
    }
}
