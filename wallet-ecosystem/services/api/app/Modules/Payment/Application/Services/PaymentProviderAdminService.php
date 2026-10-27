<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Payment\Domain\Contracts\AdapterAllowlistInterface;
use App\Modules\Payment\Domain\Enums\ProviderAccountCapability;
use App\Modules\Payment\Domain\Enums\PaymentInstrumentType;
use App\Modules\Payment\Domain\Exceptions\AdapterNotInAllowlistException;
use App\Modules\Payment\Domain\Exceptions\ConfigurationVersionConflictException;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Domain\Exceptions\UnknownCapabilityFlagException;
use App\Modules\Payment\Domain\Exceptions\UnknownInstrumentTypeException;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
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

    private const KNOWN_ISO4217 = [
        'BWP', 'USD', 'EUR', 'GBP', 'ZAR', 'ZMW', 'ZWL', 'TZS', 'KES',
        'UGX', 'NGN', 'GHS', 'ETB', 'MZN', 'AOA', 'NAD', 'MWK', 'RWF',
        'DJF', 'SCR', 'MUR', 'XAF', 'XOF',
    ];

    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly ?AdapterAllowlistInterface $adapterAllowlist = null,
        private readonly ?ActorStepUpVerifierContract $stepUpVerifier = null,
    ) {
    }

    /**
     * @return PaymentProviderModel[]
     */
    public function listProviders(): array
    {
        return PaymentProviderModel::with('accounts')
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
        $adapterIdentifier = $data['adapter_identifier'] ?? '';
        if ($this->adapterAllowlist !== null && ! $this->adapterAllowlist->isAllowed($adapterIdentifier)) {
            throw new AdapterNotInAllowlistException($adapterIdentifier);
        }

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

                // Increment config_version on each active account for this provider
                $provider->accounts()->where('is_active', true)->increment('config_version');

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
     * T036: Rotate credential reference with mandatory step-up MFA check.
     * Credential rotation ALWAYS requires step-up regardless of amount (constitution P7).
     */
    public function rotateCredentialWithStepUp(
        string $providerId,
        string $accountId,
        string $newSecretReference,
        string $actorId,
        string $sessionId,
        string $stepUpToken,
        string $correlationId,
    ): void {
        if ($this->stepUpVerifier !== null
            && ! $this->stepUpVerifier->verifyStepUpForActor(
                $stepUpToken,
                'payment.provider.credential_rotate',
                $actorId,
                $sessionId,
            )
        ) {
            throw new StepUpRequiredException();
        }

        $this->rotateCredentialReference($providerId, $accountId, $newSecretReference, $actorId, $correlationId);
    }

    /**
     * Validate capability flags map — all keys must be known ProviderAccountCapability values.
     *
     * @param array<string, mixed> $capabilityFlags
     * @throws UnknownCapabilityFlagException
     */
    public function validateCapabilityFlags(array $capabilityFlags): void
    {
        $validKeys = ProviderAccountCapability::keys();
        foreach (array_keys($capabilityFlags) as $key) {
            if (! in_array($key, $validKeys, true)) {
                throw new UnknownCapabilityFlagException($key);
            }
        }
    }

    /**
     * Validate payment instrument types — all values must be known PaymentInstrumentType values.
     *
     * @param string[] $instrumentTypes
     * @throws UnknownInstrumentTypeException
     */
    public function validateInstrumentTypes(array $instrumentTypes): void
    {
        $validValues = PaymentInstrumentType::values();
        foreach ($instrumentTypes as $type) {
            if (! in_array($type, $validValues, true)) {
                throw new UnknownInstrumentTypeException($type);
            }
        }
    }

    /**
     * Validate a draft change payload before it is stored.
     * Returns an array of field => error_code pairs; empty = valid.
     *
     * @param array<string, mixed> $changePayload
     * @return array<string, string>
     */
    public function validateDraftPayload(array $changePayload): array
    {
        $errors = [];

        // callback_url must be null — E25 owns ingress
        if (array_key_exists('callback_url', $changePayload) && $changePayload['callback_url'] !== null) {
            $errors['callback_url'] = 'CALLBACK_URL_NOT_PERMITTED';
        }

        // callback_secret_reference must match Vault/Secrets Manager path pattern
        if (isset($changePayload['callback_secret_reference'])) {
            $ref = (string) $changePayload['callback_secret_reference'];
            if (! preg_match('/^(vault:\/\/|secretsmanager:\/\/).+/', $ref)) {
                $errors['callback_secret_reference'] = 'INVALID_SECRET_REFERENCE_FORMAT';
            }
        }

        // supported_currencies must all be known ISO 4217 codes
        if (isset($changePayload['supported_currencies']) && is_array($changePayload['supported_currencies'])) {
            foreach ($changePayload['supported_currencies'] as $code) {
                if (! in_array($code, self::KNOWN_ISO4217, true)) {
                    $errors['supported_currencies'] = "INVALID_CURRENCY_CODE: {$code}";
                    break;
                }
            }
        }

        // capability_flags must only have valid keys
        if (isset($changePayload['capability_flags']) && is_array($changePayload['capability_flags'])) {
            $validKeys = ProviderAccountCapability::keys();
            foreach (array_keys($changePayload['capability_flags']) as $key) {
                if (! in_array($key, $validKeys, true)) {
                    $errors['capability_flags'] = "UNKNOWN_CAPABILITY_FLAG: {$key}";
                    break;
                }
            }
        }

        // supported_payment_methods must only have valid instrument type values
        if (isset($changePayload['supported_payment_methods']) && is_array($changePayload['supported_payment_methods'])) {
            $validValues = PaymentInstrumentType::values();
            foreach ($changePayload['supported_payment_methods'] as $method) {
                if (! in_array($method, $validValues, true)) {
                    $errors['supported_payment_methods'] = "UNKNOWN_INSTRUMENT_TYPE: {$method}";
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Apply an approved change draft to the target provider account.
     * Detects concurrent version conflicts and increments config_version atomically.
     *
     * @throws ConfigurationVersionConflictException
     */
    public function applyApprovedDraft(PaymentConfigChangeDraftModel $draft, string $actorId): void
    {
        $accountId = (string) ($draft->change_payload['account_id'] ?? '');
        if ($accountId === '') {
            return;
        }

        DB::connection('payment')->transaction(function () use ($draft, $actorId, $accountId): void {
            $account = PaymentProviderAccountModel::on('payment')
                ->lockForUpdate()
                ->find($accountId);

            if ($account === null) {
                return;
            }

            $expectedVersion = $draft->account_config_version_at_draft_time;
            if ($expectedVersion !== null && $account->config_version !== $expectedVersion) {
                throw new ConfigurationVersionConflictException($expectedVersion, $account->config_version);
            }

            $beforeVersion = $account->config_version;
            $payload       = $draft->change_payload;
            unset($payload['account_id']);

            foreach ($payload as $key => $value) {
                $account->setAttribute($key, $value);
            }
            $account->config_version = $beforeVersion + 1;
            $account->save();

            $this->audit->record(new AuditEventData(
                action:        'payment.provider.account.draft_applied',
                actorId:       $actorId,
                actorType:     'admin',
                resourceType:  'payment_provider_account',
                resourceId:    $account->id,
                correlationId: (string) ($draft->correlation_id ?? ''),
                outcome:       'success',
                afterState:    [
                    'before_version' => $beforeVersion,
                    'after_version'  => $account->config_version,
                    'draft_id'       => $draft->id,
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

    // -------------------------------------------------------------------------
    // P20: Provider account management
    // -------------------------------------------------------------------------

    /**
     * @return PaymentProviderAccountModel[]
     */
    public function listAccounts(string $providerId): array
    {
        PaymentProviderModel::on('payment')->findOrFail($providerId);

        return PaymentProviderAccountModel::on('payment')
            ->where('provider_id', $providerId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    public function getAccount(string $providerId, string $accountId): PaymentProviderAccountModel
    {
        /** @var PaymentProviderAccountModel */
        return PaymentProviderAccountModel::on('payment')
            ->where('id', $accountId)
            ->where('provider_id', $providerId)
            ->firstOrFail();
    }

    /**
     * Update non-high-risk account fields directly; flag high-risk fields for draft approval.
     *
     * @param array<string, mixed> $payload
     * @return array{type: 'saved', account: PaymentProviderAccountModel}|array{type: 'requires_approval', high_risk_fields: string[]}
     * @throws ConfigurationVersionConflictException
     */
    public function updateAccount(
        string $providerId,
        string $accountId,
        array $payload,
        string $actorId,
        string $correlationId,
        int $configVersion,
    ): array {
        $account = $this->getAccount($providerId, $accountId);

        if ($account->config_version !== $configVersion) {
            throw new ConfigurationVersionConflictException($configVersion, $account->config_version);
        }

        $highRiskFieldsChanged = array_values(array_filter(
            self::HIGH_RISK_FIELDS,
            static fn(string $field) => array_key_exists($field, $payload)
        ));

        if (count($highRiskFieldsChanged) > 0) {
            return ['type' => 'requires_approval', 'high_risk_fields' => $highRiskFieldsChanged];
        }

        DB::connection('payment')->transaction(function () use ($account, $payload, $actorId, $correlationId): void {
            foreach ($payload as $key => $value) {
                if (! in_array($key, self::HIGH_RISK_FIELDS, true)) {
                    $account->setAttribute($key, $value);
                }
            }
            $account->config_version++;
            $account->save();

            $this->audit->record(new AuditEventData(
                action:        'payment.provider.account.updated',
                actorId:       $actorId,
                actorType:     'admin',
                resourceType:  'payment_provider_account',
                resourceId:    $account->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    ['config_version' => $account->config_version],
            ));
        });

        return ['type' => 'saved', 'account' => $account->fresh() ?? $account];
    }

    /**
     * Transition a provider account through its lifecycle state machine.
     *
     * @throws \RuntimeException when the transition is not valid from the current status
     */
    public function transitionLifecycle(
        string $providerId,
        string $accountId,
        string $action,
        string $reason,
        string $actorId,
        string $correlationId,
    ): PaymentProviderAccountModel {
        $account = $this->getAccount($providerId, $accountId);

        /** @var array<string, string[]> $validTransitions */
        $validTransitions = [
            'draft'            => ['activate'],
            'pending_approval' => ['activate', 'reject'],
            'active'           => ['suspend', 'retire'],
            'suspended'        => ['restore', 'retire'],
            'retired'          => [],
            'rejected'         => ['activate'],
        ];

        $currentStatus = $account->account_lifecycle_status;
        $allowed       = $validTransitions[$currentStatus] ?? [];

        if (! in_array($action, $allowed, true)) {
            throw new \RuntimeException(
                "Invalid lifecycle transition '{$action}' from '{$currentStatus}'"
            );
        }

        $newStatus = match ($action) {
            'activate' => 'active',
            'suspend'  => 'suspended',
            'restore'  => 'active',
            'retire'   => 'retired',
            'reject'   => 'rejected',
            default    => throw new \RuntimeException("Unknown action: {$action}"),
        };

        $timestampField = match ($action) {
            'activate' => 'activated_at',
            'suspend'  => 'suspended_at',
            'retire'   => 'retired_at',
            default    => null,
        };

        DB::connection('payment')->transaction(function () use (
            $account, $newStatus, $timestampField, $action, $reason, $actorId, $correlationId
        ): void {
            $account->account_lifecycle_status = $newStatus;
            $account->is_active                = ($newStatus === 'active');
            if ($timestampField !== null) {
                $account->$timestampField = now();
            }
            $account->save();

            $this->audit->record(new AuditEventData(
                action:        "payment.provider.account.{$action}d",
                actorId:       $actorId,
                actorType:     'admin',
                resourceType:  'payment_provider_account',
                resourceId:    $account->id,
                correlationId: $correlationId,
                outcome:       'success',
                afterState:    [
                    'account_lifecycle_status' => $newStatus,
                    'reason'                   => $reason,
                ],
            ));
        });

        return $account->fresh() ?? $account;
    }

    /**
     * Safe account shape for API responses. Raw credentials are NEVER returned.
     *
     * @return array<string, mixed>
     */
    public function safeAccountShape(PaymentProviderAccountModel $account): array
    {
        $secretRef = $account->callbackSecretReference;

        return [
            'id'                         => $account->id,
            'provider_id'                => $account->provider_id,
            'merchant_account_reference' => $account->merchant_account_reference,
            'supported_currencies'       => $account->supported_currencies,
            'supported_payment_methods'  => $account->supported_payment_methods,
            'min_amount_minor'           => $account->min_amount_minor,
            'max_amount_minor'           => $account->max_amount_minor,
            'enabled_organisations'      => $account->enabled_organisations,
            'enabled_modules'            => $account->enabled_modules,
            'enabled_purpose_types'      => $account->enabled_purpose_types ?? [],
            'country_restrictions'       => $account->country_restrictions,
            'capability_flags'           => $account->capability_flags,
            'fee_rule_ids'               => $account->fee_rule_ids,
            'daily_limit_minor'          => $account->daily_limit_minor,
            'is_active'                  => $account->is_active,
            'account_lifecycle_status'   => $account->account_lifecycle_status,
            'config_version'             => $account->config_version,
            'display_label'              => $account->display_label ?? null,
            'display_description'        => $account->display_description ?? null,
            'display_icon_key'           => $account->display_icon_key ?? null,
            'display_badge'              => $account->display_badge ?? null,
            'display_sort_order'         => $account->display_sort_order ?? 0,
            'submitted_by_actor_id'      => $account->submitted_by_actor_id ?? null,
            'approved_by_actor_id'       => $account->approved_by_actor_id ?? null,
            'approved_at'                => $account->approved_at?->toIso8601String(),
            'activated_at'               => $account->activated_at?->toIso8601String() ?? null,
            'suspended_at'               => $account->suspended_at?->toIso8601String() ?? null,
            'retired_at'                 => $account->retired_at?->toIso8601String() ?? null,
            'rejection_reason'           => $account->rejection_reason ?? null,
            // callback_url intentionally OMITTED — E25 owns ingress
            'callback_secret_reference'  => $secretRef !== null
                ? ['key' => $secretRef->keyName, 'masked' => '****']
                : null,
            'created_at'                 => $account->created_at?->toIso8601String(),
            'updated_at'                 => $account->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Mask high-risk fields in a change_payload array before returning to callers.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function maskHighRiskPayload(array $payload): array
    {
        foreach (self::HIGH_RISK_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '****';
            }
        }

        return $payload;
    }
}
