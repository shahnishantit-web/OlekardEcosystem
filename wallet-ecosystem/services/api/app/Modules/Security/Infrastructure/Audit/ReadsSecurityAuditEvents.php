<?php

declare(strict_types=1);

namespace App\Modules\Security\Infrastructure\Audit;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

trait ReadsSecurityAuditEvents
{
    abstract protected function query(): Builder;

    abstract protected function source(): string;

    abstract protected function resourceType(): string;

    abstract protected function resourceId(): string;

    abstract protected function severityJson(): string;

    public function events(array $filters, ?array $cursor, int $limit): array
    {
        $action = 'lower(action)';
        $type = "CASE WHEN ($action LIKE '%login%' AND ($action LIKE '%fail%' OR outcome IN ('failure','denied'))) THEN 'login_failed' WHEN $action LIKE '%rotat%' AND ($action LIKE '%api%' OR $action LIKE '%client%' OR $action LIKE '%credential%') THEN 'api_key_rotation' WHEN ($action LIKE '%role%' OR $action LIKE '%permission%' OR $action LIKE '%privilege%') THEN 'privilege_change' WHEN ($action LIKE '%config%' OR $action LIKE '%.policy.%') THEN 'config_change' ELSE 'security' END";
        $json = $this->severityJson();
        $severity = "CASE WHEN $json IN ('critical','high','medium','low','info') THEN $json WHEN outcome IN ('failure','denied') THEN 'medium' WHEN $action LIKE '%privilege%' THEN 'high' ELSE 'info' END";
        $q = $this->query()->where(function ($q) use ($action) {
            $q->whereRaw("$action LIKE 'identity.%' OR $action LIKE 'admin.password_reset%' OR $action LIKE 'security.%' OR $action LIKE '%config%' OR $action LIKE '%role%' OR $action LIKE '%permission%' OR $action LIKE '%privilege%' OR ($action LIKE '%rotat%' AND ($action LIKE '%api%' OR $action LIKE '%client%' OR $action LIKE '%credential%'))");
        });
        if (isset($filters['event_type'])) {
            $q->whereRaw("($type) = ?", [$filters['event_type']]);
        }
        if (isset($filters['severity'])) {
            $q->whereRaw("($severity) = ?", [$filters['severity']]);
        }
        if (isset($filters['until'])) {
            $q->where('occurred_at', '<=', $filters['until']);
        }
        if ($cursor) {
            $q->where(function ($q) use ($cursor) {
                $q->where('occurred_at', '<', $cursor['at'])->orWhere(function ($q) use ($cursor) {
                    $q->where('occurred_at', '=', $cursor['at'])->where(function ($q) use ($cursor) {
                        $q->where('id', '<', $cursor['id']);
                        if (strcmp($this->source(), $cursor['source']) < 0) {
                            $q->orWhere('id', '=', $cursor['id']);
                        }
                    });
                });
            });
        }

        return $q->select(['id', 'occurred_at', 'action', 'actor_id', 'outcome', 'correlation_id'])->selectRaw($this->resourceType().' AS resource_type, '.$this->resourceId().' AS resource_id')->selectRaw("($type) AS classified_type, ($severity) AS classified_severity")->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->map(fn ($r) => ['schema_version' => 1, 'source' => $this->source(), 'id' => $r->id, 'occurred_at' => Carbon::parse($r->occurred_at)->utc()->format('Y-m-d\TH:i:s.u\Z'), 'action' => $r->action, 'event_type' => $r->classified_type, 'severity' => $r->classified_severity, 'actor_id' => $r->actor_id, 'resource_type' => $r->resource_type, 'resource_id' => $r->resource_id, 'outcome' => $r->outcome, 'correlation_id' => $r->correlation_id])->all();
    }

    public function findingTimeline(string $id): array
    {
        return $this->query()->where($this->resourceType(), 'vulnerability_finding')->where($this->resourceId(), $id)->orderBy('occurred_at')->orderBy('id')->get(['id', 'action', 'actor_id', 'after_state', 'occurred_at', 'correlation_id'])->map(function ($r) {
            $after = is_string($r->after_state) ? json_decode($r->after_state, true) : $r->after_state;

            return ['id' => $r->id, 'action' => $r->action, 'actor_id' => $r->actor_id, 'status' => $after['status'] ?? null, 'version' => $after['version'] ?? null, 'occurred_at' => Carbon::parse($r->occurred_at)->utc()->toIso8601String(), 'correlation_id' => $r->correlation_id];
        })->all();
    }
}
