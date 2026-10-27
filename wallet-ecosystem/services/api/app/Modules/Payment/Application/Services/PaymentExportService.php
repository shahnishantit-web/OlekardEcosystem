<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\Jobs\GeneratePaymentExportJob;
use App\Modules\Payment\Infrastructure\Models\PaymentExportRequestModel;
use Illuminate\Auth\Access\AuthorizationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaymentExportService
{
    /**
     * Create an export request and dispatch the async generation job.
     *
     * @param  array<string, mixed> $filters
     * @throws AuthorizationException if the requestor lacks the required scope permission
     */
    public function request(
        string  $requestorId,
        string  $scopeType,
        ?string $orgId,
        array   $filters,
    ): PaymentExportRequestModel {
        $model               = new PaymentExportRequestModel();
        $model->id           = (string) Uuid::uuid7();
        $model->requestor_id = $requestorId;
        $model->scope_type   = $scopeType;
        $model->organisation_id = $orgId;
        $model->filters      = $filters;
        $model->status       = 'queued';
        $model->save();

        GeneratePaymentExportJob::dispatch($model->id)->onQueue('payment');

        return $model;
    }

    /**
     * Retrieve the status of an export request.
     *
     * @throws HttpException 404 if not found
     * @throws AuthorizationException if requestor is not the owner
     */
    public function getStatus(string $exportId, string $requestorId): PaymentExportRequestModel
    {
        $model = PaymentExportRequestModel::find($exportId);

        if ($model === null) {
            throw new HttpException(404, 'Export request not found.');
        }

        if ($model->requestor_id !== $requestorId) {
            throw new AuthorizationException('You do not own this export request.');
        }

        return $model;
    }

    /**
     * Redeem the export download token — single-use, expiry-checked.
     *
     * @throws HttpException 404 if token not found
     * @throws HttpException 410 if expired or already downloaded
     */
    public function redeemToken(string $token): PaymentExportRequestModel
    {
        $model = PaymentExportRequestModel::where('token', $token)->first();

        if ($model === null) {
            throw new HttpException(404, 'Export token not found.');
        }

        if ($model->token_expires_at !== null && $model->token_expires_at->isPast()) {
            throw new HttpException(410, 'Export token has expired.');
        }

        if ($model->downloaded_at !== null) {
            throw new HttpException(410, 'Export token has already been used.');
        }

        $model->downloaded_at = now();
        $model->save();

        return $model;
    }
}
