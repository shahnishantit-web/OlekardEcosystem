<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;
use Illuminate\Support\Facades\DB;

/** Resolves only an existing immutable Events order with live inventory holds. */
final class PaidEventTicketPurposeResolver implements BusinessPurposeResolverInterface
{
    public function supportedPurposeType(): string
    {
        return 'paid_event_ticket';
    }

    public function resolve(string $purposeReference, string $actorId, string $correlationId): EnrichedPayableObligation
    {
        $db = DB::connection('community_services');
        $order = $db->table('community_services.event_orders')->where('id', $purposeReference)->first();
        if ($order === null || $order->purchaser_actor_id !== $actorId || $order->order_state !== 'awaiting_payment'
            || $order->total_minor === null || (int) $order->total_minor < 1) {
            throw new PurposeNotPayableException('paid_event_ticket', $purposeReference, 'A valid purchaser-owned event order is required.');
        }
        $event = $db->table('community_services.event_drafts')->where('id', $order->event_id)->first();
        if ($event === null || $event->organisation_id !== $order->organisation_id
            || $event->workflow_state !== 'published' || $event->sales_state !== 'active'
            || (int) $event->published_version !== (int) $event->version
            || $event->currency_code !== $order->currency_code
            || (int) $event->currency_precision_snapshot !== (int) $order->currency_precision) {
            throw new PurposeNotPayableException('paid_event_ticket', $purposeReference, 'Event or currency snapshot is not eligible for payment.');
        }
        $snapshot = json_decode((string) $order->pricing_snapshot, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($snapshot) || ($snapshot['seller_merchant_reference'] ?? '') === ''
            || ($snapshot['fee_policy_version'] ?? '') === '' || ($snapshot['tax_policy_version'] ?? '') === '') {
            throw new PurposeNotPayableException('paid_event_ticket', $purposeReference, 'Order pricing evidence is incomplete.');
        }
        $lines = $db->table('community_services.event_order_lines as l')
            ->join('community_services.event_inventory_holds as h', 'h.id', '=', 'l.hold_id')
            ->where('l.order_id', $purposeReference)->where('h.order_id', $purposeReference)
            ->where('h.status', 'held')->where('h.expires_at', '>', now())->get();
        $expectedLines = array_sum(array_map(static fn (array $line): int => (int) $line['quantity'],
            json_decode((string) $order->line_snapshot, true, 512, JSON_THROW_ON_ERROR)));
        if ($lines->isEmpty() || $lines->sum('quantity') !== $expectedLines) {
            throw new PurposeNotPayableException('paid_event_ticket', $purposeReference, 'Order inventory is no longer held.');
        }
        $expiry = $lines->map(static fn ($line) => new \DateTimeImmutable((string) $line->expires_at))->min();
        if ($expiry === null || $expiry <= now()) {
            throw new PurposeNotPayableException('paid_event_ticket', $purposeReference, 'Order inventory hold has expired.');
        }

        return new EnrichedPayableObligation(
            amountMinor: (int) $order->total_minor,
            currencyCode: (string) $order->currency_code,
            organisationId: (string) $order->organisation_id,
            payerActorId: (string) $order->purchaser_actor_id,
            expiresAt: $expiry,
            holdReference: $purposeReference,
            metadata: ['event_id' => $order->event_id, 'seller_merchant_reference' => $order->seller_merchant_reference],
            priceIncludesFeesAndTaxes: true,
        );
    }
}
