<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Exceptions\ResourceNotFoundException;
use App\Modules\Security\Http\Resources\VulnerabilityFindingResource;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AdminFindingService
{
    public function __construct(private readonly VulnerabilityFindingService $findings, private readonly SecurityEventService $events) {}

    /** @return array<string,mixed> */
    public function detail(string $id): array
    {
        $m = $this->findings->findById($id);
        if (! $m) {
            throw new ResourceNotFoundException('VulnerabilityFinding', $id);
        }
        $data = (new VulnerabilityFindingResource($m))->resolve();
        $data['timeline'] = array_merge([['id' => $m->id.'-detected', 'action' => 'Detected', 'status' => 'open', 'actor_id' => null, 'occurred_at' => $m->detected_at->toIso8601String()]], $this->events->findingTimeline($id));

        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function update(string $id, array $data, string $actor, string $key, string $correlation): array
    {
        if (! Uuid::isValid($key)) {
            throw new \DomainException('UUID Idempotency-Key required.');
        }
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::connection('platform')->transaction(function () use ($id, $data, $actor, $key, $correlation, $hash) {
            DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['security-finding-key:'.$key]);
            $old = DB::connection('platform')->table('security.finding_mutations')->where('request_key', $key)->first();
            if ($old) {
                if ($old->actor_id !== $actor || $old->finding_id !== $id || $old->payload_hash !== $hash) {
                    throw new \DomainException('Idempotency key conflicts with another finding change.');
                }

                return json_decode($old->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $this->findings->change($id, $data, $actor, $correlation, (int) $data['version']);
            $response = $this->detail($id);
            DB::connection('platform')->table('security.finding_mutations')->insert(['request_key' => $key, 'actor_id' => $actor, 'finding_id' => $id, 'payload_hash' => $hash, 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $response;
        });
    }
}
