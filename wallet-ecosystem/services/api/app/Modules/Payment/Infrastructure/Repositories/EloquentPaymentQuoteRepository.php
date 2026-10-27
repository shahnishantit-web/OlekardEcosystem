<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Repositories;

use App\Modules\Payment\Domain\Contracts\PaymentQuoteRepositoryInterface;
use App\Modules\Payment\Domain\Enums\QuoteStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentQuoteModel;

final class EloquentPaymentQuoteRepository implements PaymentQuoteRepositoryInterface
{
    public function save(PaymentQuoteModel $quote): void
    {
        $quote->save();
    }

    public function findById(string $id): ?PaymentQuoteModel
    {
        /** @var PaymentQuoteModel|null */
        return PaymentQuoteModel::query()->find($id);
    }

    public function findActiveForIntent(string $intentId): ?PaymentQuoteModel
    {
        /** @var PaymentQuoteModel|null */
        return PaymentQuoteModel::query()
            ->where('payment_intent_id', $intentId)
            ->where('quote_status', QuoteStatus::Active->value)
            ->orderByDesc('version')
            ->first();
    }

    /** @return PaymentQuoteModel[] */
    public function findAllForIntent(string $intentId): array
    {
        /** @var PaymentQuoteModel[] */
        return PaymentQuoteModel::query()
            ->where('payment_intent_id', $intentId)
            ->orderByDesc('version')
            ->get()
            ->all();
    }
}
