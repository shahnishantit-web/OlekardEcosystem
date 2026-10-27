<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Infrastructure\Models\CertificationEvidenceModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * T064: Manages certification evidence for integration accounts.
 *
 * Evidence is required before an account can transition to Approved status.
 * A sandbox connectivity test MUST NOT create payment_intents or payment_attempts.
 */
final class CertificationEvidenceService
{
    private const REQUIRED_EVIDENCE_TYPES = ['documentation', 'merchant_ownership'];

    public function __construct(
        private readonly AuditServiceInterface $audit,
    ) {
    }

    /** @return Collection<int, CertificationEvidenceModel> */
    public function listEvidence(string $accountId): Collection
    {
        return CertificationEvidenceModel::query()
            ->where('account_id', $accountId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @param array{evidence_type: string, description?: string, reference_url?: string, reference_document_key?: string, submitted_by_actor_id?: string} $data
     */
    public function addEvidence(string $accountId, array $data, string $actorId): CertificationEvidenceModel
    {
        $evidence = CertificationEvidenceModel::create([
            'id'                     => (string) Str::uuid7(),
            'account_id'             => $accountId,
            'evidence_type'          => $data['evidence_type'],
            'description'            => $data['description'] ?? null,
            'reference_url'          => $data['reference_url'] ?? null,
            'reference_document_key' => $data['reference_document_key'] ?? null,
            'submitted_by_actor_id'  => $actorId,
        ]);

        $this->audit->record(new AuditEventData(
            action:        'payment.certification-evidence.uploaded',
            actorType:     'admin',
            resourceType:  'payment_provider_account',
            correlationId: $accountId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $accountId,
            afterState:    [
                'evidence_id'   => $evidence->id,
                'evidence_type' => $evidence->evidence_type,
            ],
        ));

        return $evidence;
    }

    /**
     * Returns true if the account has at least one approved record of each required evidence type.
     * Used as a gate in BankAccountLifecycleService::submitEvidence() (T065).
     */
    public function hasRequiredEvidence(string $accountId): bool
    {
        $presentTypes = CertificationEvidenceModel::query()
            ->where('account_id', $accountId)
            ->whereIn('evidence_type', self::REQUIRED_EVIDENCE_TYPES)
            ->distinct()
            ->pluck('evidence_type')
            ->all();

        foreach (self::REQUIRED_EVIDENCE_TYPES as $required) {
            if (!in_array($required, $presentTypes, true)) {
                return false;
            }
        }

        return true;
    }
}
