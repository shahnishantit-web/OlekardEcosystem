<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentConfigChangeDraftRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use App\Modules\Payment\Infrastructure\Models\PaymentModuleConfigModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * P20: Module availability configuration service.
 *
 * Module config changes ALWAYS go through a draft for maker-checker approval.
 * Drafts are created directly (not via PaymentConfigDraftService) because
 * PaymentConfigDraftService::submitDraft() requires a provider_code, which
 * module config changes do not have.
 */
final class PaymentModuleConfigService
{
    public function __construct(
        private readonly PaymentConfigChangeDraftRepositoryInterface $draftRepository,
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /**
     * Return all module config rows ordered by display_order.
     *
     * @return Collection<int, PaymentModuleConfigModel>
     */
    public function list(): Collection
    {
        /** @var Collection<int, PaymentModuleConfigModel> */
        return PaymentModuleConfigModel::on('payment')
            ->orderBy('display_order')
            ->get();
    }

    /**
     * Submit a module config change for maker-checker approval.
     *
     * @param array<int, array<string, mixed>> $updates Each element must contain at minimum 'module_key'.
     */
    public function submitDraft(
        array $updates,
        string $actorId,
        string $correlationId,
    ): PaymentConfigChangeDraftModel {
        $idempotencyKey = (string) Uuid::uuid7();

        $draft = $this->draftRepository->create([
            'id'              => (string) Uuid::uuid7(),
            'provider_id'     => null,
            'change_type'     => 'module_config',
            'change_payload'  => ['modules' => $updates],
            'status'          => ConfigChangeDraftStatus::PendingApproval,
            'drafted_by_actor_id' => $actorId,
            'idempotency_key' => $idempotencyKey,
            'correlation_id'  => $correlationId,
        ]);

        $this->audit->record(new AuditEventData(
            action:        'payment.module-config.draft.submitted',
            actorId:       $actorId,
            actorType:     'admin',
            resourceType:  'payment_config_change_draft',
            resourceId:    $draft->id,
            correlationId: $correlationId,
            outcome:       'success',
            afterState:    [
                'draft_id'    => $draft->id,
                'change_type' => 'module_config',
                'module_count' => count($updates),
            ],
        ));

        return $draft;
    }

    /**
     * Apply an approved module config draft.
     * Called by PaymentConfigDraftService::approveDraft() when change_type === 'module_config'.
     */
    public function applyApprovedDraft(PaymentConfigChangeDraftModel $draft, string $actorId): void
    {
        /** @var array<int, array<string, mixed>> $modules */
        $modules = $draft->change_payload['modules'] ?? [];

        foreach ($modules as $item) {
            $moduleKey = (string) ($item['module_key'] ?? '');
            if ($moduleKey === '') {
                continue;
            }

            $updateData = array_filter(
                $item,
                static fn(string $key) => $key !== 'module_key',
                ARRAY_FILTER_USE_KEY,
            );
            $updateData['updated_by_actor_id'] = $actorId;

            PaymentModuleConfigModel::on('payment')->updateOrCreate(
                ['module_key' => $moduleKey],
                $updateData,
            );
        }

        $this->audit->record(new AuditEventData(
            action:        'payment.module-config.draft.applied',
            actorId:       $actorId,
            actorType:     'admin',
            resourceType:  'payment_config_change_draft',
            resourceId:    $draft->id,
            correlationId: (string) ($draft->correlation_id ?? ''),
            outcome:       'success',
            afterState:    [
                'draft_id'     => $draft->id,
                'module_count' => count($modules),
            ],
        ));
    }
}
