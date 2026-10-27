<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\ValueObjects\PaymentHistoryRecord;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PaymentHistoryService
{
    private const TERMINAL_REFUND_STATUSES = [
        'failed', 'cancelled', 'rejected', 'definitive_failure',
    ];

    public function getForPayer(string $payerActorId, array $filters): LengthAwarePaginator
    {
        $query = $this->buildBaseQuery()
            ->where('pi.payer_actor_id', $payerActorId);

        return $this->applyFiltersAndPaginate($query, $filters);
    }

    public function getForOrganisation(string $orgId, array $filters, ?string $payerFilter = null): LengthAwarePaginator
    {
        $query = $this->buildBaseQuery()
            ->where('pi.organisation_id', $orgId);

        if ($payerFilter !== null) {
            $query->where('pi.payer_actor_id', $payerFilter);
        }

        return $this->applyFiltersAndPaginate($query, $filters);
    }

    public function getForPlatform(array $filters): LengthAwarePaginator
    {
        $query = $this->buildBaseQuery();

        return $this->applyFiltersAndPaginate($query, $filters);
    }

    private function buildBaseQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::connection('payment')
            ->table('payment.payment_intents as pi')
            ->select([
                'pi.id as payment_reference',
                'pi.purpose_type',
                'pi.purpose_reference',
                'pi.organisation_id',
                'pi.payer_actor_id',
                'pi.currency_code',
                'pi.amount_minor as original_amount_minor',
                'pi.status as intent_status',
                'pi.fee_quote_minor',
                'pi.created_at',
                DB::raw("(
                    SELECT fee_minor
                    FROM payment.payment_attempts
                    WHERE payment_intent_id = pi.id AND status = 'succeeded'
                    ORDER BY confirmed_at DESC NULLS LAST
                    LIMIT 1
                ) as attempt_fee_minor"),
                DB::raw("(
                    SELECT provider_code
                    FROM payment.payment_attempts
                    WHERE payment_intent_id = pi.id AND status = 'succeeded'
                    ORDER BY confirmed_at DESC NULLS LAST
                    LIMIT 1
                ) as attempt_provider_code"),
                DB::raw("(
                    SELECT confirmed_at
                    FROM payment.payment_attempts
                    WHERE payment_intent_id = pi.id AND status = 'succeeded'
                    ORDER BY confirmed_at DESC NULLS LAST
                    LIMIT 1
                ) as attempt_confirmed_at"),
                DB::raw("(
                    SELECT amount_minor
                    FROM payment.payment_refunds
                    WHERE payment_intent_id = pi.id
                      AND status NOT IN ('failed', 'cancelled', 'rejected', 'definitive_failure')
                    ORDER BY created_at DESC
                    LIMIT 1
                ) as refund_amount_minor"),
                DB::raw("(
                    SELECT status
                    FROM payment.payment_refunds
                    WHERE payment_intent_id = pi.id
                      AND status NOT IN ('failed', 'cancelled', 'rejected', 'definitive_failure')
                    ORDER BY created_at DESC
                    LIMIT 1
                ) as refund_status_raw"),
            ]);
    }

    private function applyFiltersAndPaginate(\Illuminate\Database\Query\Builder $query, array $filters): LengthAwarePaginator
    {
        if (isset($filters['purpose_type']) && $filters['purpose_type'] !== '') {
            $query->where('pi.purpose_type', $filters['purpose_type']);
        }

        if (isset($filters['currency_code']) && $filters['currency_code'] !== '') {
            $query->where('pi.currency_code', $filters['currency_code']);
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->whereDate('pi.created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('pi.created_at', '<=', $filters['date_to']);
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page    = (int) ($filters['page'] ?? 1);

        // Apply status filter after mapping
        $statusFilter = $filters['status'] ?? null;

        $paginator = $query
            ->orderByDesc('pi.created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $retiredPurposes = config('payment.deep_link.retired_purposes', []);

        $paginator->through(function (object $row) use ($retiredPurposes, $statusFilter): ?PaymentHistoryRecord {
            $record = $this->mapRowToRecord($row, $retiredPurposes);

            if ($statusFilter !== null && $record->paymentStatus !== $statusFilter) {
                return null;
            }

            return $record;
        });

        return $paginator;
    }

    protected function mapRowToRecord(object $row, array $retiredPurposes): PaymentHistoryRecord
    {
        $intentStatus     = (string) $row->intent_status;
        $refundStatusRaw  = $row->refund_status_raw ? (string) $row->refund_status_raw : null;
        $refundAmountMinor = $row->refund_amount_minor ? (int) $row->refund_amount_minor : null;

        $feeAmountMinor  = $row->attempt_fee_minor !== null
            ? (int) $row->attempt_fee_minor
            : (int) ($row->fee_quote_minor ?? 0);

        $originalAmountMinor = (int) $row->original_amount_minor;
        $totalChargedMinor   = $originalAmountMinor + $feeAmountMinor;

        $safeStatus   = $this->mapIntentStatus($intentStatus, $refundStatusRaw);
        $refundStatus = $this->mapRefundStatus($refundStatusRaw);
        $refundEligible = $this->computeRefundEligible($intentStatus, $refundStatusRaw);

        $domainDeepLink = $this->buildDeepLink(
            (string) $row->purpose_type,
            (string) $row->purpose_reference,
            $retiredPurposes,
        );

        $providerLabel = $row->attempt_provider_code
            ? $this->formatProviderLabel((string) $row->attempt_provider_code)
            : null;

        $paymentDate = $row->attempt_confirmed_at ?? $row->created_at;

        return new PaymentHistoryRecord(
            paymentReference:    (string) $row->payment_reference,
            purposeType:         (string) $row->purpose_type,
            purposeReference:    (string) $row->purpose_reference,
            domainDeepLink:      $domainDeepLink,
            providerLabel:       $providerLabel,
            originalAmountMinor: $originalAmountMinor,
            currencyCode:        (string) $row->currency_code,
            feeAmountMinor:      $feeAmountMinor,
            totalChargedMinor:   $totalChargedMinor,
            paymentStatus:       $safeStatus,
            refundStatus:        $refundStatus,
            refundAmountMinor:   $refundAmountMinor,
            refundEligible:      $refundEligible,
            paymentDate:         (string) $paymentDate,
            organisationId:      (string) $row->organisation_id,
        );
    }

    protected function mapIntentStatus(string $intentStatus, ?string $refundStatusRaw): string
    {
        return match ($intentStatus) {
            'pending', 'processing', 'awaiting_action', 'requires_confirmation' => 'processing',
            'refunding', 'partially_refunded'                                    => 'refund_pending',
            'refunded'                                                           => 'refunded',
            'failed'                                                             => 'failed',
            'cancelled', 'expired'                                               => 'cancelled',
            'succeeded' => $this->mapSucceededWithRefund($refundStatusRaw),
            default     => 'processing',
        };
    }

    private function mapSucceededWithRefund(?string $refundStatusRaw): string
    {
        if ($refundStatusRaw === null) {
            return 'confirmed';
        }

        if (in_array($refundStatusRaw, ['confirmed', 'succeeded'], true)) {
            return 'refunded';
        }

        return 'refund_pending';
    }

    private function mapRefundStatus(?string $refundStatusRaw): string
    {
        if ($refundStatusRaw === null) {
            return 'none';
        }

        if (in_array($refundStatusRaw, ['confirmed', 'succeeded'], true)) {
            return 'confirmed';
        }

        return 'pending';
    }

    private function computeRefundEligible(string $intentStatus, ?string $refundStatusRaw): bool
    {
        if ($intentStatus !== 'succeeded') {
            return false;
        }

        if ($refundStatusRaw === null) {
            return true;
        }

        // Active non-terminal refund exists — not eligible for another
        return false;
    }

    protected function buildDeepLink(string $purposeType, string $purposeReference, array $retiredPurposes): string|array
    {
        if (in_array($purposeType, $retiredPurposes, true)) {
            return ['status' => 'unavailable', 'reason' => 'domain_retired'];
        }

        return "{$purposeType}://{$purposeReference}";
    }

    private function formatProviderLabel(string $providerCode): string
    {
        $labelMap = config('payment.provider_labels', []);

        return $labelMap[$providerCode] ?? ucwords(str_replace(['_', '-'], ' ', $providerCode));
    }
}
