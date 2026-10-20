<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * T065: Payment provider admin service.
 *
 * Manages provider CRUD, suspension, and credential reference rotation.
 * High-risk field changes (merchant_account_reference) are flagged for approval.
 * Credentials are NEVER returned — only secret references are stored.
 */
final class PaymentProviderAdminService
{
    private const HIGH_RISK_FIELDS = [
        'merchant_account_reference',
        'callback_secret_reference',
        'settlement_destination',
    ];

    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /**
     * @return PaymentProviderModel[]
     */
    public function listProviders(): array
    {
        return PaymentProviderModel::with('accounts')
            ->connection('payment')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function findProvider(string $id): ?PaymentProviderModel
    {
        return PaymentProviderModel::on('payment')
            ->with('accounts')
            ->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createProvider(array $data, string $actorId, string $correlationId): PaymentProviderModel
    {
        return DB::connection('payment')->transaction(function () use ($data, $actorId, $correlationId): PaymentProviderModel {
            $provider = new PaymentProviderModel();
            $provider->id               = (string) Uuid::uuid7();
            $provider->code             = $data['code'];
            $provider->display_name     = $data['display_name'];
            $provider->adapter_identifier = $data['adapter_identifier'];
            $provider->adapter_version  = $data['adapter_version'] ?? '1.0.0';
            $provider->environment      = $data['environment'] ?? 'sandbox';
            $provider->is_enabled       = false; // disabled by default
            $provider->is_suspended     = false;
            $provider->is_default       = $data['is_default'] ?? false;
            $provider->sort_order       = $data['sort_order'] ?? 99;
            $provider->save();

            $this->audit->record(new AuditEventData(
                action:        'payment.provider.created',
                actorId:       $actorId,
                actorType:     'admin',
                resourceType:  'payment_provider',
                resourceId:    $provider->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    ['code' => $provider->code, 'environment' => $provider->environment],
            ));

            return $provider;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array{provider: PaymentProviderModel, requires_approval: bool, high_risk_fields: string[]}
     */
    public function updateProvider(string $id, array $data, string $actorId, string $correlationId): array
    {
        $provider = PaymentProviderModel::on('payment')->findOrFail($id);

        $highRiskFieldsChanged = array_values(array_filter(
            self::HIGH_RISK_FIELDS,
            static fn(string $field) => isset($data[$field])
        ));

        $requiresApproval = count($highRiskFieldsChanged) > 0;

        if (! $requiresApproval) {
            DB::connection('payment')->transaction(function () use ($provider, $data, $actorId, $correlationId): void {
                foreach ($data as $key => $value) {
                    if (in_array($key, self::HIGH_RISK_FIELDS, true)) {
                        continue;
                    }
                    $provider->setAttribute($key, $value);
                }
                $provider->save();

                $this->audit->record(new AuditEventData(
                    action:        'payment.provider.updated',
                    actorId:       $actorId,
                    actorType:     'admin',
                    resourceType:  'payment_provider',
                    resourceId:    $provider->id,
                    correlationId: $correlationId,
                    outcome:       'success',
                    afterState:    $data,
                ));
            });
        }

        return [
            'provider'          => $provider->fresh() ?? $provider,
            'requires_approval' => $requiresApproval,
            'high_risk_fields'  => $highRiskFieldsChanged,
        ];
    }

    public function suspendProvider(string $id, string $actorId, string $correlationId): PaymentProviderModel
    {
        $provider = PaymentProviderModel::on('payment')->findOrFail($id);

        DB::connection('payment')->transaction(function () use ($provider, $actorId, $correlationId): void {
            $provider->is_suspended = true;
            $provider->save();

            $this->audit->record(new AuditEventData(
                action:        'payment.provider.suspended',
                actorId:       $actorId,
                actorType:     'admin',
                resourceType:  'payment_provider',
                resourceId:    $provider->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    ['is_suspended' => true],
            ));
        });

        return $provider->fresh() ?? $provider;
    }

    /**
     * Rotate the credential secret reference (not the value).
     * The new reference points to the updated Vault/Secrets Manager path.
     */
    public function rotateCredentialReference(
        string $providerId,
        string $accountId,
        string $newSecretReference,
        string $actorId,
        string $correlationId,
    ): void {
        $account = PaymentProviderAccountModel::on('payment')
            ->where('id', $accountId)
            ->where('provider_id', $providerId)
            ->firstOrFail();

        DB::connection('payment')->transaction(function () use (
            $account, $newSecretReference, $actorId, $correlationId, $providerId
        ): void {
            $account->callback_secret_reference = $newSecretReference;
            $account->save();

            // Audit with reference path only — NEVER log the actual secret value
            $this->audit->record(new AuditEventData(
                action:        'payment.provider.credential_rotated',
                actorId:       $actorId,
                actorType:     'admin',
                resourceType:  'payment_provider_account',
                resourceId:    $account->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    [
                    'provider_id'           => $providerId,
                    'new_reference_path'    => $newSecretReference,
                ],
            ));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function safeProviderShape(PaymentProviderModel $provider): array
    {
        return [
            'id'                  => $provider->id,
            'code'                => $provider->code,
            'display_name'        => $provider->display_name,
            'adapter_identifier'  => $provider->adapter_identifier,
            'environment'         => $provider->environment,
            'is_enabled'          => $provider->is_enabled,
            'is_suspended'        => $provider->is_suspended,
            'is_default'          => $provider->is_default,
            'sort_order'          => $provider->sort_order,
            'created_at'          => $provider->created_at?->toIso8601String(),
            'updated_at'          => $provider->updated_at?->toIso8601String(),
            // NEVER include callback_secret_reference or any credential value
        ];
    }
}
