<?php

declare(strict_types=1);

namespace App\Modules\Events\Tests\E2E;

use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\ValueObjects\ComplimentaryAllocationApprovalEvidence;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Synthetic event/category approval for the isolated P26 browser test only. */
final readonly class P26BrowserE2EComplimentaryApproval implements ComplimentaryAllocationApprovalContract
{
    /** @param array<string, array{organisation_id:string, category_ids:list<string>}> $scopes */
    public function __construct(
        private array $scopes,
    ) {}

    public function approvalEvidence(string $organisationId, string $eventId, string $categoryId): ComplimentaryAllocationApprovalEvidence
    {
        $scope = $this->scopes[$eventId] ?? null;
        if ($scope === null || $organisationId !== $scope['organisation_id']
            || ! in_array($categoryId, $scope['category_ids'], true)) {
            throw new HttpException(403, 'P26 E2E fixture approval scope mismatch.');
        }

        $category = DB::connection('community_services')->table('community_services.event_draft_categories')
            ->where('id', $categoryId)->where('event_id', $eventId)->first();
        if ($category === null || $category->allocation_type !== 'complimentary') {
            throw new HttpException(403, 'P26 E2E fixture is not a complimentary allocation.');
        }

        return new ComplimentaryAllocationApprovalEvidence(
            approvalId: 'p26-e2e-approval-'.$eventId.'-'.$categoryId,
            version: 1,
            organisationId: $organisationId,
            eventId: $eventId,
            categoryId: $categoryId,
            approvedQuota: (int) $category->complimentary_quota,
            requestedByActorId: 'p26-e2e-requester',
            approvedByActorId: 'p26-e2e-approver',
            validFrom: new DateTimeImmutable('-1 minute'),
            expiresAt: new DateTimeImmutable('+1 day'),
            revokedAt: null,
            provenance: 'fixture://p26-isolated-e2e',
        );
    }
}
