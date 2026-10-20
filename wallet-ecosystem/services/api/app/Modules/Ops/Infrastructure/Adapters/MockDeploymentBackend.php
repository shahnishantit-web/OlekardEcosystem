<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Adapters;

use App\Modules\Ops\Domain\Contracts\DeploymentBackendInterface;
use Illuminate\Support\Facades\Log;

final class MockDeploymentBackend implements DeploymentBackendInterface
{
    public function rollback(string $fromVersion, string $toVersion): void
    {
        Log::info('[MockDeploymentBackend] Simulating rollback', [
            'from' => $fromVersion,
            'to'   => $toVersion,
        ]);

        // In production, replace with actual deployment backend calls
        // (e.g., Kubernetes rollout undo, ArgoCD sync, Helm rollback).
    }
}
