<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum AccountConfigLifecycleStatus: string
{
    case Draft          = 'draft';
    case Validated      = 'validated';
    case SandboxTesting = 'sandbox_testing';
    case EvidenceReview = 'evidence_review';
    case Approved       = 'approved';
    case Active         = 'active';
    case Suspended      = 'suspended';
    case Retired        = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft          => 'Draft',
            self::Validated      => 'Validated',
            self::SandboxTesting => 'Sandbox Testing',
            self::EvidenceReview => 'Evidence Review',
            self::Approved       => 'Approved',
            self::Active         => 'Active',
            self::Suspended      => 'Suspended',
            self::Retired        => 'Retired',
        };
    }

    /** @return array<AccountConfigLifecycleStatus> */
    public function allowedTransitionsFrom(): array
    {
        return match ($this) {
            self::Draft          => [self::Validated],
            self::Validated      => [self::SandboxTesting, self::Draft],
            self::SandboxTesting => [self::EvidenceReview, self::Draft],
            self::EvidenceReview => [self::Approved, self::Draft],
            self::Approved       => [self::Active, self::Draft],
            self::Active         => [self::Suspended, self::Retired],
            self::Suspended      => [self::Active, self::Retired],
            self::Retired        => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitionsFrom(), true);
    }

    /**
     * The evidence_review → approved transition requires the approver to differ from the submitter.
     */
    public function requiresApproverDistinct(): bool
    {
        return $this === self::EvidenceReview;
    }
}
