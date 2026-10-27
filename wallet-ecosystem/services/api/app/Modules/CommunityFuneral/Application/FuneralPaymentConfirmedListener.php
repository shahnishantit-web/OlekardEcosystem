<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application;

final class FuneralPaymentConfirmedListener
{
    public function __construct(private readonly FuneralAssistanceService $funerals) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        if (($payload['purpose_type'] ?? null) !== 'funeral_contribution') {
            return;
        }
        $this->funerals->recordPaymentConfirmation($payload);
    }
}
