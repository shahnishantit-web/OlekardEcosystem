<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OrganiserRepresentativeVerifier;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\FinancialTermsApprovalEvidenceVerifier;
use App\Modules\Platform\Domain\Contracts\OleKardTermsAcceptanceQuery;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Versioned OleKard terms; policy values are supplied by authorised owners, never defaulted here. */
final class OleKardFinancialTermsService implements OleKardTermsAcceptanceQuery
{
    private const REQUIRED_CLAUSES = [
        'seller_identity', 'fees', 'tax', 'refund_eligibility', 'refund_deadlines', 'fee_refundability',
        'provider_charge_treatment', 'funding_responsibility', 'partial_refunds',
        'admitted_ticket_refunds', 'late_capture', 'in_flight_payments', 'currency_rounding',
    ];

    public function __construct(
        private readonly IdentityModuleContract $identity,
        private readonly OrganiserRepresentativeVerifier $representatives,
        private readonly AuditServiceInterface $audit,
        private readonly FinancialTermsApprovalEvidenceVerifier $approvalEvidence,
    ) {}

    private function db(): Connection
    {
        return DB::connection('platform');
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function draft(array $content, string $effectiveFrom, ?string $effectiveUntil, string $actorId, string $idempotencyKey, string $correlationId): array
    {
        $this->requirePermission($actorId, 'platform.financial_terms.draft');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A bounded idempotency key is required.']);
        }
        [$from, $until] = $this->dates($effectiveFrom, $effectiveUntil);
        $encoded = $this->canonicalJson($content);
        $hash = hash('sha256', $encoded);

        return $this->db()->transaction(function () use ($content, $from, $until, $actorId, $idempotencyKey, $correlationId, $hash): array {
            $existing = $this->db()->table('platform.olekard_financial_terms_versions')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless($existing->created_by_actor_id === $actorId && hash_equals((string) $existing->content_hash, $hash), 409, 'Terms draft idempotency key conflicts with its original request.');

                return (array) $existing;
            }
            $this->db()->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('olekard-financial-terms-version', 0))");
            $version = 1 + (int) $this->db()->table('platform.olekard_financial_terms_versions')->max('version');
            $row = [
                'id' => (string) Uuid::uuid7(), 'version' => $version, 'content' => json_encode($content, JSON_THROW_ON_ERROR),
                'content_hash' => $hash, 'effective_from' => $from, 'effective_until' => $until,
                'status' => 'draft', 'test_only' => app()->environment('testing'),
                'created_by_actor_id' => $actorId, 'idempotency_key' => $idempotencyKey,
                'correlation_id' => $correlationId, 'created_at' => now(), 'updated_at' => now(),
            ];
            $this->db()->table('platform.olekard_financial_terms_versions')->insert($row);
            $this->recordAudit('platform.financial_terms.drafted', $row['id'], $actorId, $correlationId, ['version' => $version, 'content_hash' => $hash]);

            return $row;
        }, 3);
    }

    /** @return array<string,mixed> */
    public function approve(string $versionId, string $actorId, string $approvalReference, string $correlationId): array
    {
        $this->requirePermission($actorId, 'platform.financial_terms.approve');
        abort_if(trim($approvalReference) === '', 422, 'An approval evidence reference is required.');

        return $this->db()->transaction(function () use ($versionId, $actorId, $approvalReference, $correlationId): array {
            $version = $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->lockForUpdate()->first();
            abort_if($version === null, 404, 'Terms version was not found.');
            abort_unless($version->status === 'draft', 409, 'Only a draft terms version can be approved.');
            abort_unless(! $version->test_only || app()->environment('testing'), 409, 'Test-only terms cannot be approved outside the test environment.');
            abort_unless($version->created_by_actor_id !== $actorId, 403, 'The terms author cannot approve their own version.');
            $content = json_decode((string) $version->content, true, 512, JSON_THROW_ON_ERROR);
            $this->assertPublishableContent($content);
            try {
                $evidence = $this->approvalEvidence->verify($approvalReference, $actorId, (int) $version->version, (string) $version->content_hash);
                $evidence->assertMatches($actorId, (int) $version->version, (string) $version->content_hash, now()->toImmutable());
            } catch (\Throwable) {
                throw ValidationException::withMessages(['approval_reference' => 'Authoritative Finance/Governance approval evidence is missing, invalid or unavailable.']);
            }
            $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->update([
                'status' => 'approved', 'approved_by_actor_id' => $actorId,
                'approval_reference' => $evidence->reference,
                'approval_evidence_provenance' => $evidence->evidenceProvenance,
                'approval_terms_version' => $evidence->termsVersion,
                'approval_content_hash' => $evidence->contentHash,
                'approved_at' => $evidence->approvedAt, 'updated_at' => now(),
            ]);
            $this->recordAudit('platform.financial_terms.approved', $versionId, $actorId, $correlationId, ['version' => (int) $version->version, 'content_hash' => $version->content_hash]);

            return (array) $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->first();
        }, 3);
    }

    /** @return array<string,mixed> */
    public function publish(string $versionId, string $actorId, string $correlationId): array
    {
        $this->requirePermission($actorId, 'platform.financial_terms.publish');

        return $this->db()->transaction(function () use ($versionId, $actorId, $correlationId): array {
            // Serialize effective-period checks so overlapping versions cannot both publish.
            $this->db()->selectOne("SELECT pg_advisory_xact_lock(hashtextextended('olekard-financial-terms-version', 0))");
            $version = $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->lockForUpdate()->first();
            abort_if($version === null, 404, 'Terms version was not found.');
            abort_unless($version->status === 'approved' && $version->approved_by_actor_id !== null
                && $version->approval_reference !== null && $version->approval_evidence_provenance !== null
                && (int) $version->approval_terms_version === (int) $version->version
                && $version->approval_content_hash !== null
                && hash_equals((string) $version->content_hash, (string) $version->approval_content_hash),
                409, 'Verified approval evidence must be present before publication.');
            abort_unless(! $version->test_only || app()->environment('testing'), 409, 'Test-only terms cannot be published outside the test environment.');
            $from = new \DateTimeImmutable((string) $version->effective_from);
            $until = $version->effective_until === null ? null : new \DateTimeImmutable((string) $version->effective_until);
            $overlap = $this->db()->table('platform.olekard_financial_terms_versions')->where('status', 'published')
                ->where('id', '<>', $versionId)->where('effective_from', '<', $until ?? '9999-12-31 23:59:59+00')
                ->where(static function ($query) use ($from): void {
                    $query->whereNull('effective_until')->orWhere('effective_until', '>', $from);
                })->exists();
            abort_if($overlap, 409, 'Published OleKard terms effective periods cannot overlap.');
            $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->update([
                'status' => 'published', 'published_by_actor_id' => $actorId, 'published_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordAudit('platform.financial_terms.published', $versionId, $actorId, $correlationId, ['version' => (int) $version->version, 'content_hash' => $version->content_hash]);

            return (array) $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->first();
        }, 3);
    }

    /** @return array<string,mixed> */
    public function accept(string $versionId, string $organisationId, string $eventId, string $actorId, string $idempotencyKey, string $correlationId): array
    {
        abort_if($idempotencyKey === '' || strlen($idempotencyKey) > 128, 422, 'A bounded idempotency key is required.');

        return $this->db()->transaction(function () use ($versionId, $organisationId, $eventId, $actorId, $idempotencyKey, $correlationId): array {
            $existingKey = $this->db()->table('platform.olekard_financial_terms_acceptances')
                ->where('organisation_id', $organisationId)->where('event_id', $eventId)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existingKey !== null) {
                abort_unless($existingKey->accepted_by_actor_id === $actorId && $existingKey->terms_version_id === $versionId, 409, 'Terms acceptance idempotency key conflicts with its original request.');

                return (array) $existingKey;
            }
            $version = $this->currentVersion($versionId);
            try {
                $evidence = $this->representatives->verifyForEventTerms($organisationId, $actorId, $eventId);
                $evidence->assertCurrentFor($organisationId, $actorId, $eventId, now()->toImmutable());
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['representative' => 'Current verified organiser representative authority is required.']);
            }
            $already = $this->db()->table('platform.olekard_financial_terms_acceptances')
                ->where('terms_version_id', $versionId)->where('organisation_id', $organisationId)->where('event_id', $eventId)->lockForUpdate()->first();
            if ($already !== null) {
                abort_unless($already->accepted_by_actor_id === $actorId, 409, 'This organisation and event already accepted this terms version through another representative.');

                return (array) $already;
            }
            $row = [
                'id' => (string) Uuid::uuid7(), 'terms_version_id' => $versionId,
                'organisation_id' => $organisationId, 'event_id' => $eventId, 'accepted_by_actor_id' => $actorId,
                'representative_verification_id' => $evidence->verificationId,
                'representative_verification_version' => $evidence->version,
                'representative_evidence_reference' => $evidence->evidenceReference,
                'representative_verified_at' => $evidence->verifiedAt, 'representative_valid_until' => $evidence->validUntil,
                'accepted_at' => now(), 'accepted_content_hash' => $version->content_hash,
                'idempotency_key' => $idempotencyKey, 'correlation_id' => $correlationId, 'created_at' => now(), 'updated_at' => now(),
            ];
            $this->db()->table('platform.olekard_financial_terms_acceptances')->insert($row);
            $this->recordAudit('platform.financial_terms.accepted', $row['id'], $actorId, $correlationId, ['terms_version' => (int) $version->version, 'organisation_id' => $organisationId, 'event_id' => $eventId]);

            return $row;
        }, 3);
    }

    public function currentAcceptanceSnapshot(string $organisationId, string $eventId): ?array
    {
        $now = now();
        $versionQuery = $this->db()->table('platform.olekard_financial_terms_versions')->where('status', 'published')
            ->where('effective_from', '<=', $now)->where(static function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $now);
            })
            ->orderByDesc('version');
        if (! app()->environment('testing')) {
            $versionQuery->where('test_only', false);
        }
        $version = $versionQuery->first();
        if ($version === null) {
            return null;
        }
        if ($version->approval_reference === null || $version->approved_by_actor_id === null
            || (int) $version->approval_terms_version !== (int) $version->version
            || $version->approval_content_hash === null
            || ! hash_equals((string) $version->content_hash, (string) $version->approval_content_hash)
            || trim((string) $version->approval_evidence_provenance) === '') {
            return null;
        }
        $acceptance = $this->db()->table('platform.olekard_financial_terms_acceptances')
            ->where('terms_version_id', $version->id)->where('organisation_id', $organisationId)->where('event_id', $eventId)->first();
        if ($acceptance === null || ! hash_equals((string) $version->content_hash, (string) $acceptance->accepted_content_hash)) {
            return null;
        }
        try {
            $evidence = $this->representatives->verifyForEventTerms($organisationId, (string) $acceptance->accepted_by_actor_id, $eventId);
            $evidence->assertCurrentFor($organisationId, (string) $acceptance->accepted_by_actor_id, $eventId, $now->toImmutable());
        } catch (\Throwable) {
            return null;
        }

        return [
            'acceptance_id' => (string) $acceptance->id, 'version_id' => (string) $version->id,
            'accepted_version_id' => (string) $version->id, 'version' => (string) $version->version,
            'approval_reference' => (string) $version->approval_reference,
            'approved_by_actor_id' => (string) $version->approved_by_actor_id,
            'approved_at' => (string) $version->approved_at, 'document_hash' => (string) $version->content_hash,
            'accepted_document_hash' => (string) $acceptance->accepted_content_hash,
            'acceptance_provenance' => (string) $acceptance->representative_evidence_reference,
            'organisation_id' => $organisationId, 'event_id' => $eventId,
            'accepted_by_actor_id' => (string) $acceptance->accepted_by_actor_id,
            'accepted_at' => (string) $acceptance->accepted_at,
            'effective_from' => (string) $version->effective_from,
            'effective_until' => (string) ($version->effective_until ?? '9999-12-31T23:59:59+00:00'),
        ];
    }

    public function withPublicationGuard(Closure $operation): mixed
    {
        $connection = $this->db();
        $connection->selectOne("SELECT pg_advisory_lock(hashtextextended('olekard-financial-terms-version', 0))");
        try {
            return $operation();
        } finally {
            $connection->selectOne("SELECT pg_advisory_unlock(hashtextextended('olekard-financial-terms-version', 0))");
        }
    }

    private function currentVersion(string $versionId): object
    {
        $now = now();
        $version = $this->db()->table('platform.olekard_financial_terms_versions')->where('id', $versionId)->lockForUpdate()->first();
        abort_if($version === null, 404, 'Terms version was not found.');
        $start = new \DateTimeImmutable((string) $version->effective_from);
        $end = $version->effective_until === null ? null : new \DateTimeImmutable((string) $version->effective_until);
        abort_unless($version->status === 'published' && $version->approval_reference !== null
            && $version->approval_evidence_provenance !== null && $version->approved_by_actor_id !== null
            && (int) $version->approval_terms_version === (int) $version->version
            && $version->approval_content_hash !== null
            && hash_equals((string) $version->content_hash, (string) $version->approval_content_hash)
            && (! $version->test_only || app()->environment('testing'))
            && $start <= $now->toImmutable() && ($end === null || $end > $now->toImmutable()), 409,
            'Only the currently effective, production-eligible published terms version can be accepted.');

        return $version;
    }

    private function requirePermission(string $actorId, string $permission): void
    {
        abort_unless($this->identity->hasPermission($actorId, $permission), 403, 'Dedicated OleKard financial-terms permission is required.');
    }

    /** @param array<string,mixed> $content */
    private function assertPublishableContent(array $content): void
    {
        foreach (self::REQUIRED_CLAUSES as $clause) {
            if (! array_key_exists($clause, $content) || $content[$clause] === null || $content[$clause] === '' || $content[$clause] === []) {
                throw ValidationException::withMessages(['content.'.$clause => 'Approved terms must explicitly define this policy clause.']);
            }
        }
        $this->canonicalJson($content);
    }

    /** @return array{0:\DateTimeImmutable,1:?\DateTimeImmutable} */
    private function dates(string $from, ?string $until): array
    {
        try {
            $start = new \DateTimeImmutable($from);
            $end = $until === null ? null : new \DateTimeImmutable($until);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['effective_from' => 'Terms effective dates must be valid timestamps.']);
        }
        abort_unless($end === null || $end > $start, 422, 'Terms effective_until must be after effective_from.');

        return [$start, $end];
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (is_float($item)) {
                throw ValidationException::withMessages(['content' => 'Financial terms must use decimal strings or integer minor units, never binary floating point.']);
            }
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }

            return $item;
        };

        return json_encode($normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $state */
    private function recordAudit(string $action, string $id, string $actorId, string $correlationId, array $state): void
    {
        $this->audit->record(new AuditEventData(
            action: $action, actorType: 'USER', resourceType: 'OleKardFinancialTerms',
            correlationId: $correlationId, outcome: 'success', actorId: $actorId, resourceId: $id,
            afterState: $state,
        ));
    }
}
