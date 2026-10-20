<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class AgentApplicationDocumentRejectedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $applicationId,
        public string $documentId,
        public string $documentType,
        public string $actorType,
        public string $reason,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'agent.application.document_rejected'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'application_id' => $this->applicationId,
            'document_id'    => $this->documentId,
            'document_type'  => $this->documentType,
            'actor_type'     => $this->actorType,
            'reason'         => $this->reason,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
