<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Security\Domain\Contracts\SecurityAuditSource;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class SecurityEventService
{
    /** @param list<SecurityAuditSource> $sources */
    public function __construct(private readonly array $sources, private readonly SecurityAuditWriter $audit) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function page(array $filters, ?string $cursor = null, int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \DomainException('Invalid page limit.');
        }
        foreach (['severity' => ['critical', 'high', 'medium', 'low', 'info'], 'event_type' => ['login_failed', 'privilege_change', 'config_change', 'api_key_rotation', 'security']] as $key => $allowed) {
            if (isset($filters[$key]) && ! in_array($filters[$key], $allowed, true)) {
                throw new \DomainException('Invalid event filter.');
            }
        }
        $decoded = null;
        if ($cursor !== null) {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (! is_array($decoded) || ! isset($decoded['at'],$decoded['id'],$decoded['source']) || ! is_string($decoded['at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $decoded['at']) || ! is_string($decoded['id']) || ! Uuid::isValid($decoded['id']) || ! in_array($decoded['source'], ['platform', 'audit'], true)) {
                throw new \DomainException('Invalid event cursor.');
            }try {
                Carbon::parse($decoded['at']);
            } catch (\Throwable) {
                throw new \DomainException('Invalid cursor timestamp.');
            }
        }
        $items = [];
        foreach ($this->sources as $source) {
            $items = array_merge($items, $source->events($filters, $decoded, $limit + 1));
        }
        usort($items, fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']) ?: strcmp($b['id'], $a['id']) ?: strcmp($b['source'], $a['source']));
        $more = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $last = $items[count($items) - 1] ?? null;

        return ['items' => $items, 'next_cursor' => $more && $last ? base64_encode(json_encode(['at' => $last['occurred_at'], 'id' => $last['id'], 'source' => $last['source']], JSON_THROW_ON_ERROR)) : null];
    }

    /** @return list<array<string,mixed>> */
    public function findingTimeline(string $id): array
    {
        $items = [];
        $seen = [];
        foreach ($this->sources as $s) {
            foreach ($s->findingTimeline($id) as $row) {
                $key = isset($row['version']) ? $row['correlation_id'].'|'.$row['action'].'|'.$row['version'] : $row['id'];
                if (! isset($seen[$key])) {
                    $items[] = $row;
                    $seen[$key] = true;
                }
            }
        }
        usort($items, fn ($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']) ?: strcmp($a['id'], $b['id']));

        return $items;
    }

    /** @param array<string,mixed> $filters */
    public function export(array $filters, string $actor, string $correlation): \Generator
    {
        $filters['until'] = now()->toIso8601String();
        $first = $this->page($filters, null, 100);
        $this->audit->record('security.events.exported', 'security_event_export', (string) Uuid::uuid7(), $actor, $correlation, [], ['filters' => array_intersect_key($filters, array_flip(['severity', 'event_type']))]);

        return (function () use ($first, $filters) {
            $page = $first;
            do {
                foreach ($page['items'] as $row) {
                    yield json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                }$cursor = $page['next_cursor'];
                if ($cursor !== null) {
                    $page = $this->page($filters, $cursor, 100);
                }
            } while ($cursor !== null);
        })();
    }
}
