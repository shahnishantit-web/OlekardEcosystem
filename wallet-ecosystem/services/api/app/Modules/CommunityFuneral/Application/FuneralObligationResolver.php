<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application;

use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use Illuminate\Support\Facades\DB;

final class FuneralObligationResolver implements BusinessPurposeResolverInterface
{
    public function supportedPurposeType(): string
    {
        return 'funeral_contribution';
    }

    public function resolve(string $purposeReference, string $actorId, string $correlationId): EnrichedPayableObligation
    {
        if (config('community_funeral.http_enabled', false) !== true || app()->environment('production')) {
            throw new PurposeNotPayableException('funeral_contribution', $purposeReference, 'Funeral Assistance is unavailable pending prerequisite approval.');
        }

        $row = DB::connection('community_services')->table('community_services.funeral_obligations as o')
            ->join('community_services.funeral_collections as c', 'c.id', '=', 'o.collection_id')
            ->join('community_services.funeral_communities as m', 'm.id', '=', 'c.community_id')
            ->join('community_services.funeral_representatives as r', 'r.household_id', '=', 'o.household_id')
            ->where('o.id', $purposeReference)->where('r.actor_id', $actorId)->where('r.status', 'verified')
            ->where('o.status', 'unpaid')->where('c.status', 'active')
            ->select(['o.amount_minor', 'o.currency_code', 'm.organisation_id', 'o.household_id', 'o.collection_id'])
            ->first();

        if ($row === null) {
            throw new PurposeNotPayableException('funeral_contribution', $purposeReference, 'Obligation is unavailable to this actor.');
        }

        return new EnrichedPayableObligation(
            amountMinor: (int) $row->amount_minor,
            currencyCode: (string) $row->currency_code,
            organisationId: (string) $row->organisation_id,
            payerActorId: $actorId,
            holdReference: null,
            beneficiaryAllocations: [],
            metadata: ['funeral_collection_id' => $row->collection_id, 'household_id' => $row->household_id],
        );
    }
}
