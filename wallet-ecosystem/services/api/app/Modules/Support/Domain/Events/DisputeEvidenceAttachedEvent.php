<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeEvidenceAttachedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $disputeId,
        public string $evidenceId,
        public string $uploadedById,
        public string $fileType,
        public int    $sizeBytes,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.EvidenceAttached'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'     => $this->disputeId,
            'evidence_id'    => $this->evidenceId,
            'uploaded_by_id' => $this->uploadedById,
            'file_type'      => $this->fileType,
            'size_bytes'     => $this->sizeBytes,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
