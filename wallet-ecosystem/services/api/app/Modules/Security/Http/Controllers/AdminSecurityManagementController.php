<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Modules\Security\Application\Services\AdminFindingService;
use App\Modules\Security\Application\Services\SecurityEventService;
use App\Modules\Security\Application\Services\SecurityPolicyAdminService;
use App\Modules\Security\Domain\Enums\VulnerabilityFindingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rules\Enum;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminSecurityManagementController extends Controller
{
    public function __construct(private readonly SecurityPolicyAdminService $policies, private readonly AdminFindingService $findings, private readonly SecurityEventService $events) {}

    private function correlation(Request $r): string
    {
        $v = (string) $r->header('X-Correlation-ID');

        return Uuid::isValid($v) ? $v : (string) Uuid::uuid7();
    }

    private function run(Request $r, callable $fn, int $status = 200): JsonResponse
    {
        $c = $this->correlation($r);
        try {
            return response()->json(['data' => $fn((string) $r->attributes->get('identity.user_id'), (string) $r->header('Idempotency-Key'), $c), 'correlation_id' => $c], $status, ['X-Correlation-ID' => $c]);
        } catch (\DomainException $e) {
            return response()->json(['error' => ['code' => 'SECURITY_POLICY_INVALID', 'message' => $e->getMessage()], 'correlation_id' => $c], 422, ['X-Correlation-ID' => $c]);
        }
    }

    public function config(Request $r): JsonResponse
    {
        return $this->run($r, fn () => $this->policies->index());
    }

    public function propose(Request $r): JsonResponse
    {
        $d = $r->validate(['key' => ['required', 'string', 'max:255'], 'value' => ['present']]);

        return $this->run($r, fn ($a, $k, $c) => $this->policies->propose($d['key'], $d['value'], $a, $k, $c), 202);
    }

    public function approve(Request $r, string $id): JsonResponse
    {
        return $this->run($r, fn ($a, $k, $c) => $this->policies->approve($id, $a, $k, $c, (string) $r->ip()));
    }

    public function finding(Request $r, string $id): JsonResponse
    {
        return $this->run($r, fn () => $this->findings->detail($id));
    }

    public function updateFinding(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['sometimes', new Enum(VulnerabilityFindingStatus::class)], 'cvss_score' => ['nullable', 'numeric', 'min:0', 'max:10', 'decimal:0,1'], 'remediation_steps' => ['nullable', 'string', 'max:20000'], 'suppression_justification' => ['nullable', 'string', 'max:1000']]);

        return $this->run($r, fn ($a, $k, $c) => $this->findings->update($id, $d, $a, $k, $c));
    }

    /** @return array<string,mixed> */
    private function filters(Request $r): array
    {
        return $r->validate(['severity' => ['sometimes', 'in:critical,high,medium,low,info'], 'event_type' => ['sometimes', 'in:login_failed,privilege_change,config_change,api_key_rotation,security']]);
    }

    public function events(Request $r): JsonResponse
    {
        $f = $this->filters($r);
        $d = $r->validate(['cursor' => ['sometimes', 'string', 'max:1024'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return $this->run($r, fn () => $this->events->page($f, $d['cursor'] ?? null, (int) ($d['limit'] ?? 25)));
    }

    public function export(Request $r): StreamedResponse
    {
        $f = $this->filters($r);
        $lines = $this->events->export($f, (string) $r->attributes->get('identity.user_id'), $this->correlation($r));

        return response()->streamDownload(function () use ($lines) {
            foreach ($lines as $line) {
                echo $line;
            }
        }, 'security-events.ndjson', ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'no-store', 'X-Correlation-ID' => $this->correlation($r)]);
    }
}
