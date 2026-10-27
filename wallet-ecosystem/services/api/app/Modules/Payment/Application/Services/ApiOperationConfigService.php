<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Enums\AccountConfigLifecycleStatus;
use App\Modules\Payment\Infrastructure\Models\ApiOperationConfigModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T044: Manages API operation configurations for integration accounts.
 * Integrates SSRF guard and field mapping validator before persistence.
 */
final class ApiOperationConfigService
{
    public function __construct(
        private readonly ApiOperationConfigValidator $validator,
        private readonly AuditServiceInterface       $audit,
    ) {
    }

    /** @return Collection<int, ApiOperationConfigModel> */
    public function listOperations(string $accountId): Collection
    {
        return ApiOperationConfigModel::query()
            ->where('account_id', $accountId)
            ->whereNull('config_version_id')
            ->get();
    }

    /**
     * Validate and persist operation configs.
     * If account is Active, creates a new Draft account record (SC-008 / FR-020).
     *
     * @param array<int, array<string, mixed>> $operations
     * @return array{saved: array<int, ApiOperationConfigModel>, draft_created: bool}
     */
    public function saveOperations(string $accountId, array $operations, string $actorId): array
    {
        $account = PaymentProviderAccountModel::query()->findOrFail($accountId);

        $validationErrors = $this->validateOperations($accountId, $operations);

        if (!empty($validationErrors)) {
            throw new \InvalidArgumentException(
                'Operation configuration validation failed: '.json_encode($validationErrors)
            );
        }

        $draftCreated = false;
        $targetAccountId = $accountId;

        if ($account->account_lifecycle_status === AccountConfigLifecycleStatus::Active->value) {
            $draft            = $this->createDraftFromActive($account, $actorId);
            $targetAccountId  = $draft->id;
            $draftCreated     = true;
        }

        $saved = DB::connection('payment')->transaction(function () use ($targetAccountId, $operations, $actorId): array {
            // Remove existing draft-level configs for this account
            ApiOperationConfigModel::query()
                ->where('account_id', $targetAccountId)
                ->whereNull('config_version_id')
                ->delete();

            $records = [];

            foreach ($operations as $opData) {
                $model = ApiOperationConfigModel::create(array_merge($opData, [
                    'id'               => (string) Str::uuid7(),
                    'account_id'       => $targetAccountId,
                    'config_version_id' => null,
                    'is_payout'        => false,
                ]));

                $records[] = $model;
            }

            return $records;
        });

        $this->audit->record(new AuditEventData(
            action:        'payment.operation-config.saved',
            actorType:     'admin',
            resourceType:  'api_operation_config',
            correlationId: $accountId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $targetAccountId,
            afterState:    ['operation_count' => count($saved), 'draft_created' => $draftCreated],
        ));

        return ['saved' => $saved, 'draft_created' => $draftCreated];
    }

    /**
     * Validate operations without persisting. Returns field-keyed error map.
     *
     * @param array<int, array<string, mixed>> $operations
     * @return array<int, array<string, string>>
     */
    public function validateOperations(string $accountId, array $operations): array
    {
        $allErrors = [];

        foreach ($operations as $i => $opData) {
            $errors = $this->validator->validate($opData);

            if (!empty($errors)) {
                $allErrors[$i] = $errors;
            }
        }

        return $allErrors;
    }

    private function createDraftFromActive(PaymentProviderAccountModel $active, string $actorId): PaymentProviderAccountModel
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
        $draft->save();

        return $draft;
    }
}
