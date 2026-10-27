<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Enums\AccountConfigLifecycleStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class IntegrationAccountService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /** @return Collection<int, PaymentProviderAccountModel> */
    public function listAccounts(string $bankId, array $filters = []): Collection
    {
        $query = PaymentProviderAccountModel::query()->where('provider_id', $bankId);

        if (!empty($filters['status'])) {
            $query->where('account_lifecycle_status', $filters['status']);
        }

        return $query->orderBy('display_sort_order')->get();
    }

    public function findAccount(string $id): ?PaymentProviderAccountModel
    {
        return PaymentProviderAccountModel::query()->find($id);
    }

    /**
     * @param array{provider_id: string, merchant_account_reference: string, environment: string, supported_currencies?: array<string>, display_label?: string, display_sort_order?: int} $data
     */
    public function createAccount(array $data, string $actorId): PaymentProviderAccountModel
    {
        $account = new PaymentProviderAccountModel();
        $account->fill([
            'id'                          => (string) Str::uuid7(),
            'provider_id'                 => $data['provider_id'],
            'merchant_account_reference'  => $data['merchant_account_reference'],
            'supported_currencies'        => $data['supported_currencies'] ?? [],
            'supported_payment_methods'   => $data['supported_payment_methods'] ?? [],
            'min_amount_minor'            => $data['min_amount_minor'] ?? 0,
            'max_amount_minor'            => $data['max_amount_minor'] ?? PHP_INT_MAX,
            'enabled_organisations'       => $data['enabled_organisations'] ?? [],
            'enabled_modules'             => $data['enabled_modules'] ?? [],
            'country_restrictions'        => $data['country_restrictions'] ?? [],
            'callback_url'                => $data['callback_url'] ?? null,
            'callback_secret_reference'   => $data['callback_secret_reference'] ?? null,
            'capability_flags'            => $data['capability_flags'] ?? [],
            'fee_rule_ids'                => $data['fee_rule_ids'] ?? [],
            'daily_limit_minor'           => $data['daily_limit_minor'] ?? null,
            'is_active'                   => false,
            'config_version'              => 1,
            'enabled_purpose_types'       => $data['enabled_purpose_types'] ?? [],
            'display_description'         => $data['display_description'] ?? null,
            'display_icon_key'            => $data['display_icon_key'] ?? null,
            'display_badge'               => $data['display_badge'] ?? null,
            'account_lifecycle_status'    => AccountConfigLifecycleStatus::Draft->value,
            'display_label'               => $data['display_label'] ?? null,
            'display_sort_order'          => $data['display_sort_order'] ?? 0,
        ]);

        $account->save();

        $this->audit->record(new AuditEventData(
            action:        'payment.integration-account.created',
            actorType:     'admin',
            resourceType:  'payment_provider_account',
            correlationId: $account->id,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $account->id,
            afterState:    [
                'provider_id' => $account->provider_id,
                'status'      => $account->account_lifecycle_status,
            ],
        ));

        return $account;
    }

    /**
     * FR-020: ALL edits to an Active account MUST create a new Draft version regardless of which
     * fields changed. There is no lightweight "minor edit" path — this ensures the full lifecycle
     * (including two-person approval) is always traversed before any change reaches Active.
     *
     * @param array<string, mixed> $data
     */
    public function updateAccount(string $id, array $data, int $expectedConfigVersion, string $actorId): PaymentProviderAccountModel
    {
        $account = $this->findAccount($id);

        if ($account === null) {
            throw new \RuntimeException("Integration account not found: {$id}");
        }

        if ($account->config_version !== $expectedConfigVersion) {
            throw new \RuntimeException("Optimistic lock conflict: expected config_version {$expectedConfigVersion}, got {$account->config_version}");
        }

        $isActive = $account->account_lifecycle_status === AccountConfigLifecycleStatus::Active->value;

        if ($isActive) {
            // FR-020: editing an Active account creates a new Draft — never mutates the Active one.
            return $this->createDraftFromActive($account, $data, $actorId);
        }

        $allowed = [
            'merchant_account_reference', 'supported_currencies', 'supported_payment_methods',
            'min_amount_minor', 'max_amount_minor', 'enabled_organisations', 'enabled_modules',
            'country_restrictions', 'callback_url', 'callback_secret_reference', 'capability_flags',
            'fee_rule_ids', 'daily_limit_minor', 'enabled_purpose_types', 'display_description',
            'display_icon_key', 'display_badge', 'display_label', 'display_sort_order',
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $account->$field = $data[$field];
            }
        }

        $account->save();

        $this->audit->record(new AuditEventData(
            action:        'payment.integration-account.updated',
            actorType:     'admin',
            resourceType:  'payment_provider_account',
            correlationId: $id,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $id,
        ));

        return $account;
    }

    /**
     * FR-020: Creates a new Draft account copying data from the Active one then applying changes.
     * The original Active account remains untouched.
     *
     * @param array<string, mixed> $data
     */
    private function createDraftFromActive(PaymentProviderAccountModel $active, array $data, string $actorId): PaymentProviderAccountModel
    {
        $draft = new PaymentProviderAccountModel();
        $draft->fill($active->toArray());
        $draft->id                       = (string) Str::uuid7();
        $draft->account_lifecycle_status = AccountConfigLifecycleStatus::Draft->value;
        $draft->active_config_version_id = null;
        $draft->submitted_by_actor_id    = null;
        $draft->approved_by_actor_id     = null;
        $draft->approved_at              = null;
        $draft->activated_at             = null;
        $draft->suspended_at             = null;
        $draft->retired_at               = null;
        $draft->rejection_reason         = null;
        $draft->config_version           = 1;

        $allowed = [
            'merchant_account_reference', 'supported_currencies', 'supported_payment_methods',
            'min_amount_minor', 'max_amount_minor', 'enabled_organisations', 'enabled_modules',
            'country_restrictions', 'callback_url', 'callback_secret_reference', 'capability_flags',
            'fee_rule_ids', 'daily_limit_minor', 'enabled_purpose_types', 'display_description',
            'display_icon_key', 'display_badge', 'display_label', 'display_sort_order',
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $draft->$field = $data[$field];
            }
        }

        $draft->save();

        $this->audit->record(new AuditEventData(
            action:        'payment.integration-account.draft-created-from-active',
            actorType:     'admin',
            resourceType:  'payment_provider_account',
            correlationId: $active->id,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $draft->id,
            afterState:    ['source_account_id' => $active->id, 'draft_id' => $draft->id],
        ));

        return $draft;
    }
}
