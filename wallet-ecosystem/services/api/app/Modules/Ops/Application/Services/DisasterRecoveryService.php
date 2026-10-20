<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Platform\Application\Services\ConfigurationService;

final class DisasterRecoveryService
{
    public function __construct(
        private readonly ConfigurationService $config,
    ) {}

    public function getStatus(): array
    {
        $runbookVersion = (string) $this->config->getOrDefault('ops.dr.runbook_version', '1.0');
        $lastDrTestAt   = $this->config->getOrDefault('ops.dr.last_dr_test_at', null);
        $rtoHours       = (int) $this->config->getOrDefault('ops.dr.rto_hours', 4);
        $rpoMinutes     = (int) $this->config->getOrDefault('ops.dr.rpo_minutes', 60);

        return [
            'runbook_version'    => $runbookVersion,
            'last_dr_test_at'    => $lastDrTestAt,
            'rto_hours'          => $rtoHours,
            'rpo_minutes'        => $rpoMinutes,
            'runbook_url'        => '/docs/disaster-recovery-runbook.md',
        ];
    }

    public function recordTest(string $testedBy, string $outcome, string $notes, string $correlationId): array
    {
        $testedAt = now()->toAtomString();

        return [
            'tested_at'      => $testedAt,
            'tested_by'      => $testedBy,
            'outcome'        => $outcome,
            'notes'          => $notes,
            'correlation_id' => $correlationId,
        ];
    }
}
