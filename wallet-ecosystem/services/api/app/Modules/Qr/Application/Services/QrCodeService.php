<?php

declare(strict_types=1);

namespace App\Modules\Qr\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Qr\Domain\Contracts\MerchantReadServiceInterface;
use App\Modules\Qr\Domain\DTO\GenerateQrCodeInput;
use App\Modules\Qr\Domain\Enums\QrCodeStatus;
use App\Modules\Qr\Domain\Enums\QrCodeType;
use App\Modules\Qr\Domain\Exceptions\MerchantNotActiveException;
use App\Modules\Qr\Domain\Exceptions\QrNotActiveException;
use App\Modules\Qr\Infrastructure\Models\QrCodeModel;
use Illuminate\Contracts\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class QrCodeService
{
    public function __construct(
        private readonly QrPayloadSigner              $signer,
        private readonly MerchantReadServiceInterface $merchantRead,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface        $audit,
    ) {}

    public function generate(GenerateQrCodeInput $input, string $merchantId, string $correlationId): QrCodeModel
    {
        if (! $this->merchantRead->isActive($merchantId)) {
            throw new MerchantNotActiveException();
        }

        $nonce     = Str::orderedUuid()->toString();
        $expiresAt = null;

        $fields = array_filter([
            'type'         => $input->type->value,
            'merchant_id'  => $merchantId,
            'currency'     => $input->currency,
            'merchant_ref' => $input->merchantRef,
            'nonce'        => $nonce,
        ], fn ($v) => $v !== null);

        if ($input->type === QrCodeType::Dynamic && $input->amount !== null) {
            $fields['amount'] = (int) round((float) $input->amount * 100);

            $expirySeconds = $input->expirySeconds
                ?? (int) ($this->config->get('qr.dynamic_expiry_seconds') ?? 300);

            $expiresAt             = now()->addSeconds($expirySeconds);
            $fields['expires_at']  = $expiresAt->toIso8601String();
        }

        $payload = $this->signer->sign($fields);

        $qrCode = new QrCodeModel();
        $qrCode->id           = Str::orderedUuid()->toString();
        $qrCode->merchant_id  = $merchantId;
        $qrCode->type         = $input->type;
        $qrCode->label        = $input->label;
        $qrCode->amount       = isset($fields['amount']) ? $fields['amount'] : null;
        $qrCode->currency     = $input->currency;
        $qrCode->merchant_ref = $input->merchantRef;
        $qrCode->nonce        = $nonce;
        $qrCode->payload      = $payload;
        $qrCode->status       = QrCodeStatus::Active;
        $qrCode->expires_at   = $expiresAt;
        $qrCode->save();

        $this->audit->record(new AuditEventData(
            action:        'qr.code.generated',
            actorType:     'merchant',
            resourceType:  'qr_code',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $merchantId,
            resourceId:    $qrCode->id,
            afterState:    ['type' => $input->type->value, 'status' => QrCodeStatus::Active->value],
        ));

        return $qrCode;
    }

    public function deactivate(string $id, string $merchantId, string $correlationId): QrCodeModel
    {
        $qrCode = $this->findForMerchant($id, $merchantId);

        $qrCode->status = QrCodeStatus::Inactive;
        $qrCode->save();

        $this->audit->record(new AuditEventData(
            action:        'qr.code.deactivated',
            actorType:     'merchant',
            resourceType:  'qr_code',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $merchantId,
            resourceId:    $qrCode->id,
            afterState:    ['status' => QrCodeStatus::Inactive->value],
        ));

        return $qrCode;
    }

    public function findForMerchant(string $id, string $merchantId): QrCodeModel
    {
        /** @var QrCodeModel|null $qrCode */
        $qrCode = QrCodeModel::where('id', $id)->first();

        if ($qrCode === null || $qrCode->merchant_id !== $merchantId) {
            throw new QrNotActiveException();
        }

        return $qrCode;
    }

    public function listForMerchant(string $merchantId, array $filters = []): LengthAwarePaginator
    {
        $query = QrCodeModel::where('merchant_id', $merchantId)
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->paginate(20);
    }
}
