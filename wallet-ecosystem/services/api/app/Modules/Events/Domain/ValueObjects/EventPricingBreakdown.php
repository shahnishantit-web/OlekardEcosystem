<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\ValueObjects;

final readonly class EventPricingBreakdown
{
    /**
     * @param  list<array{code:string,amount_minor:int,policy_version:string}>  $feeComponents
     * @param  list<array{code:string,amount_minor:int,policy_version:string}>  $taxComponents
     * @param  array<string,mixed>|null  $organiserFeeScheduleSnapshot
     * @param  array<string,mixed>|null  $taxPolicySnapshot
     * @param  array<string,mixed>|null  $oleKardTermsAcceptanceSnapshot
     */
    public function __construct(
        public int $subtotalMinor,
        public int $feesMinor,
        public int $taxesMinor,
        public int $totalMinor,
        public string $currencyCode,
        public int $currencyPrecision,
        public string $sellerMerchantReference,
        public string $feePolicyVersion,
        public string $taxPolicyVersion,
        public array $feeComponents,
        public array $taxComponents,
        public \DateTimeImmutable $policyExpiresAt,
        public string $policyEvidenceReference,
        public bool $policyApproved,
        public bool $sellerVerified,
        public bool $testOnly,
        public ?int $organiserPlatformFeeMinor = null,
        public ?array $organiserFeeScheduleSnapshot = null,
        public ?array $taxPolicySnapshot = null,
        public ?array $oleKardTermsAcceptanceSnapshot = null,
    ) {
        if ($subtotalMinor < 1 || $feesMinor < 0 || $taxesMinor < 0
            || $totalMinor !== $subtotalMinor + $feesMinor + $taxesMinor
            || preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1 || $currencyPrecision < 0
            || trim($sellerMerchantReference) === '' || trim($feePolicyVersion) === ''
            || trim($taxPolicyVersion) === '' || trim($policyEvidenceReference) === '') {
            throw new \InvalidArgumentException('Pricing requires a complete immutable monetary and seller-policy snapshot.');
        }
        $this->validateComponents($feeComponents, $feesMinor);
        $this->validateComponents($taxComponents, $taxesMinor);
        if ($organiserPlatformFeeMinor !== null && $organiserPlatformFeeMinor < 0) {
            throw new \InvalidArgumentException('Organiser platform fee must be a non-negative minor-unit amount.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'subtotal_minor' => $this->subtotalMinor,
            'fees_minor' => $this->feesMinor,
            'taxes_minor' => $this->taxesMinor,
            'total_minor' => $this->totalMinor,
            'currency_code' => $this->currencyCode,
            'currency_precision' => $this->currencyPrecision,
            'seller_merchant_reference' => $this->sellerMerchantReference,
            'fee_policy_version' => $this->feePolicyVersion,
            'tax_policy_version' => $this->taxPolicyVersion,
            'fee_components' => $this->feeComponents,
            'tax_components' => $this->taxComponents,
            'organiser_platform_fee_minor' => $this->organiserPlatformFeeMinor,
            'organiser_fee_schedule_snapshot' => $this->organiserFeeScheduleSnapshot,
            'tax_policy_snapshot' => $this->taxPolicySnapshot,
            'olekard_terms_acceptance_snapshot' => $this->oleKardTermsAcceptanceSnapshot,
            'policy_expires_at' => $this->policyExpiresAt->format(DATE_ATOM),
            'policy_evidence_reference' => $this->policyEvidenceReference,
            'policy_approved' => $this->policyApproved,
            'seller_verified' => $this->sellerVerified,
            'test_only' => $this->testOnly,
        ];
    }

    /** @return array<string,mixed> Values whose change requires renewed customer confirmation. */
    public function confirmationSnapshot(): array
    {
        $snapshot = $this->toArray();
        unset($snapshot['policy_expires_at'], $snapshot['policy_evidence_reference']);

        return $snapshot;
    }

    public function assertEnvironmentEligible(): void
    {
        if ($this->testOnly) {
            if (! app()->environment('testing')) {
                throw new \DomainException('Test-only Events pricing is unavailable outside the test environment.');
            }

            return;
        }
        if (! $this->policyApproved || ! $this->sellerVerified) {
            throw new \DomainException('Approved Finance pricing policy and verified seller evidence are required.');
        }
        if ($this->organiserPlatformFeeMinor === null || $this->organiserFeeScheduleSnapshot === null
            || $this->taxPolicySnapshot === null) {
            throw new \DomainException('Production pricing requires explicit organiser fee schedule and approved tax snapshots.');
        }
        $terms = $this->oleKardTermsAcceptanceSnapshot;
        $requiredTerms = ['acceptance_id', 'version_id', 'accepted_version_id', 'version', 'approval_reference',
            'approved_by_actor_id', 'approved_at', 'document_hash', 'accepted_document_hash', 'acceptance_provenance',
            'organisation_id', 'event_id', 'accepted_by_actor_id', 'accepted_at', 'effective_from', 'effective_until'];
        if ($terms === null || array_diff($requiredTerms, array_keys($terms)) !== []) {
            throw new \DomainException('Production pricing requires an approved, effective OleKard terms version and organiser acceptance snapshot.');
        }
        foreach ($requiredTerms as $key) {
            if (! is_string($terms[$key]) || trim($terms[$key]) === '') {
                throw new \DomainException('OleKard terms approval and acceptance evidence must be complete.');
            }
        }
        $documentHash = $terms['document_hash'] ?? null;
        if (! is_string($documentHash) || preg_match('/^[a-f0-9]{64}$/D', $documentHash) !== 1) {
            throw new \DomainException('OleKard terms must carry the hash of their immutable accepted content.');
        }
        if ($terms['version_id'] !== $terms['accepted_version_id']
            || ! hash_equals($documentHash, (string) $terms['accepted_document_hash'])) {
            throw new \DomainException('Organiser must accept the current OleKard terms version and exact content before paid capabilities continue.');
        }
        if ($this->feesMinor !== 0 || $this->feeComponents !== []) {
            throw new \DomainException('Customer-payable booking fees are unavailable without a separately approved policy.');
        }
        $this->validateOrganiserFeeSchedule($this->organiserFeeScheduleSnapshot);
        $this->validateTaxPolicySnapshot($this->taxPolicySnapshot);
    }

    /** @param list<array{code:string,amount_minor:int,policy_version:string}> $components */
    private function validateComponents(array $components, int $expectedTotal): void
    {
        $sum = 0;
        foreach ($components as $component) {
            if (($component['code'] ?? '') === '' || ($component['policy_version'] ?? '') === ''
                || ! is_int($component['amount_minor'] ?? null) || $component['amount_minor'] < 0) {
                throw new \InvalidArgumentException('Every priced component needs a code, non-negative amount and policy version.');
            }
            $sum += $component['amount_minor'];
        }
        if ($sum !== $expectedTotal) {
            throw new \InvalidArgumentException('Pricing component amounts must equal their corresponding total.');
        }
    }

    /** @param array<string,mixed> $schedule */
    private function validateOrganiserFeeSchedule(array $schedule): void
    {
        $required = ['version', 'approval_evidence_reference', 'effective_from', 'effective_until', 'currency_code', 'calculation_basis', 'scope', 'components'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $schedule)) {
                throw new \DomainException('Organiser fee schedule is missing required versioned scope data.');
            }
        }
        if ($schedule['version'] !== $this->feePolicyVersion || trim((string) $schedule['approval_evidence_reference']) === ''
            || $schedule['currency_code'] !== $this->currencyCode
            || trim((string) $schedule['calculation_basis']) === '' || ! is_array($schedule['scope'])
            || ! in_array($schedule['scope']['type'] ?? null, ['organisation', 'event'], true)
            || trim((string) ($schedule['scope']['id'] ?? '')) === '' || ! is_array($schedule['components'])
            || $schedule['components'] === []) {
            throw new \DomainException('Organiser fee schedule does not match the approved currency, version or scope.');
        }
        try {
            $start = new \DateTimeImmutable((string) $schedule['effective_from']);
            $end = isset($schedule['effective_until']) ? new \DateTimeImmutable((string) $schedule['effective_until']) : null;
        } catch (\Throwable $exception) {
            throw new \DomainException('Organiser fee schedule effective dates are invalid.', previous: $exception);
        }
        if ($end !== null && $end <= $start) {
            throw new \DomainException('Organiser fee schedule effective period is invalid.');
        }

        $sum = 0;
        foreach ($schedule['components'] as $component) {
            if (! is_array($component) || ! in_array($component['type'] ?? null, ['fixed', 'percentage'], true)
                || ($component['payer'] ?? null) !== 'organiser' || ! is_string($component['value'] ?? null)
                || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $component['value']) !== 1
                || ! is_int($component['amount_minor'] ?? null) || $component['amount_minor'] < 0) {
                throw new \DomainException('Fee components must preserve fixed/percentage basis, decimal value and organiser payer.');
            }
            $sum += $component['amount_minor'];
        }
        if ($sum !== $this->organiserPlatformFeeMinor) {
            throw new \DomainException('Organiser fee schedule components do not match the immutable fee amount.');
        }
        $this->rejectFloatingPoint($schedule);
    }

    /** @param array<string,mixed> $snapshot */
    private function validateTaxPolicySnapshot(array $snapshot): void
    {
        $required = [
            'version', 'approval_evidence_reference', 'effective_from', 'effective_until', 'jurisdiction', 'ticket_classification', 'ticket_treatment', 'service_fee_treatment',
            'price_presentation',
            'rates', 'exemptions', 'taxable_bases', 'calculation_order', 'currency_precision',
            'rounding_scope', 'rounding_method', 'disclosures', 'invoice_responsibility',
        ];
        foreach ($required as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new \DomainException('Tax policy snapshot is missing required qualified Finance/Tax inputs.');
            }
        }
        if ($snapshot['version'] !== $this->taxPolicyVersion || trim((string) $snapshot['approval_evidence_reference']) === ''
            || trim((string) $snapshot['jurisdiction']) === ''
            || trim((string) $snapshot['ticket_classification']) === '' || trim((string) $snapshot['ticket_treatment']) === ''
            || trim((string) $snapshot['service_fee_treatment']) === '' || ! is_array($snapshot['rates'])
            || ! in_array($snapshot['price_presentation'], ['inclusive', 'exclusive'], true)
            || ! is_array($snapshot['exemptions']) || ($snapshot['rates'] === [] && $snapshot['exemptions'] === [])
            || ! is_array($snapshot['taxable_bases']) || $snapshot['taxable_bases'] === []
            || ! is_array($snapshot['calculation_order']) || $snapshot['calculation_order'] === []
            || ($snapshot['currency_precision'] ?? null) !== $this->currencyPrecision
            || trim((string) $snapshot['rounding_scope']) === '' || trim((string) $snapshot['rounding_method']) === ''
            || ! is_array($snapshot['disclosures']) || $snapshot['disclosures'] === []
            || trim((string) $snapshot['invoice_responsibility']) === '') {
            throw new \DomainException('Tax policy snapshot is incomplete or inconsistent with the immutable order currency.');
        }
        try {
            $start = new \DateTimeImmutable((string) $snapshot['effective_from']);
            $end = $snapshot['effective_until'] === null ? null : new \DateTimeImmutable((string) $snapshot['effective_until']);
        } catch (\Throwable $exception) {
            throw new \DomainException('Tax policy effective dates are invalid.', previous: $exception);
        }
        if ($end !== null && $end <= $start) {
            throw new \DomainException('Tax policy effective period is invalid.');
        }
        $this->rejectFloatingPoint($snapshot);
    }

    private function rejectFloatingPoint(mixed $value): void
    {
        if (is_float($value)) {
            throw new \DomainException('Pricing policy snapshots must represent monetary and rate values without binary floating point.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->rejectFloatingPoint($item);
            }
        }
    }
}
