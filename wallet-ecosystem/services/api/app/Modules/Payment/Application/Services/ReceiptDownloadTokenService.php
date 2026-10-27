<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Infrastructure\Models\ReceiptDownloadTokenModel;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReceiptDownloadTokenService
{
    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {
    }

    /**
     * Issue a single-use, expiring download token for an authorised requestor.
     *
     * Ownership is verified via ReceiptService::compile() before issuing.
     *
     * @throws \App\Exceptions\ResourceNotFoundException if intent not found or requestor not payer
     */
    public function issue(string $intentId, string $requestorId): ReceiptDownloadTokenModel
    {
        // Verify ownership before issuing token (FR-001, FR-007)
        $this->receiptService->compile($intentId, $requestorId);

        $ttlMinutes = (int) config('payment.receipt.download_token_ttl_minutes', 15);

        $token = new ReceiptDownloadTokenModel();
        $token->id                = (string) Uuid::uuid7();
        $token->payment_intent_id = $intentId;
        $token->requestor_id      = $requestorId;
        $token->token             = bin2hex(random_bytes(32));
        $token->expires_at        = Carbon::now()->addMinutes($ttlMinutes);
        $token->save();

        return $token;
    }

    /**
     * Redeem a token: validate it is not expired or already used, then mark it used.
     *
     * @throws HttpException 410 if the token is expired or already used
     * @throws HttpException 404 if the token does not exist
     */
    public function redeem(string $token): ReceiptDownloadTokenModel
    {
        $model = ReceiptDownloadTokenModel::where('token', $token)->first();

        if ($model === null) {
            throw new HttpException(404, 'Download token not found.');
        }

        if ($model->isExpired()) {
            throw new HttpException(410, 'Download token has expired.');
        }

        if ($model->isUsed()) {
            throw new HttpException(410, 'Download token has already been used.');
        }

        $model->used_at = Carbon::now();
        $model->save();

        return $model;
    }
}
