<?php

declare(strict_types=1);

namespace App\Modules\Security\Application\Services;

use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\Services\ConfigurationChangeRequestService;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\ValueObjects\SecurityPolicyRegistry as Registry;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class SecurityPolicyAdminService
{
    public function __construct(private readonly ConfigurationServiceInterface $config, private readonly ConfigurationChangeRequestService $changes, private readonly ConfigurationChangeRequestRepositoryInterface $requests, private readonly RoleRepositoryInterface $roles, private readonly SecurityAuditWriter $audit) {}

    /** @return array<string,mixed> */
    public function index(): array
    {
        $items = [];
        foreach (Registry::definitions() as $key => $d) {
            $row = DB::connection('platform')->table('platform.configuration_values')->where('config_key', $key)->where('status', 'active')->orderByDesc('version')->first();
            $request = $row?->change_request_id ? $this->requests->findById($row->change_request_id) : null;
            $checker = $request?->approvals()->where('decision', 'approved')->orderByDesc('decided_at')->value('checker_id');
            $pending = $this->requests->findPendingByKey($key);
            $value = $row ? Registry::unwrap(json_decode((string) $row->config_value, true) ?? $row->config_value) : $d['default'];
            // Existing scalar config rows may predate typed E28 payloads.
            if ($d['type'] === 'boolean' && ! is_bool($value)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            if (in_array($d['type'], ['integer', 'nullable_integer'], true) && $value !== null) {
                $value = (int) $value;
            }
            $items[] = array_merge($d, ['key' => $key, 'value' => $value, 'configured' => $row !== null, 'last_modified_by' => $checker ?? ($row?->last_updated_by ?? null), 'last_modified_at' => $row?->updated_at, 'pending' => $pending ? ['id' => $pending->id, 'maker_id' => $pending->maker_id, 'value' => Registry::unwrap($pending->proposed_value)] : null]);
        }

        return ['settings' => $items, 'roles' => $this->roles->findAll()->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->display_name])->values()->all()];
    }

    /** @return array<string,mixed> */
    public function propose(string $key, mixed $value, string $actor, string $idempotency, string $correlation): array
    {
        Registry::validate($key, $value);
        $this->validateRoles($key, $value);
        if (! Uuid::isValid($idempotency)) {
            throw new \DomainException('UUID Idempotency-Key required.');
        }

        return DB::connection('platform')->transaction(function () use ($key, $value, $actor, $idempotency, $correlation) {
            $this->lock('security-idem:'.$idempotency);
            $this->lock('security-policy:'.$key);
            $old = $this->requests->findByIdempotencyKey($idempotency);
            if ($old && ($old->config_key !== $key || $old->maker_id !== $actor || $this->canonical(Registry::unwrap($old->proposed_value)) !== $this->canonical($value))) {
                throw new \DomainException('Idempotency key conflicts with another proposal.');
            }
            $r = $this->changes->submit(new SubmitChangeRequestDTO($key, ['value' => $value], $actor, $idempotency, $correlation));
            if (! $old) {
                $this->audit->record('security.policy.submitted', 'security_policy', $r['id'], $actor, $correlation, [], ['key' => $key, 'value' => $value]);
            }

            return $r;
        });
    }

    /** @return array<string,mixed> */
    public function approve(string $id, string $actor, string $key, string $correlation, string $ip): array
    {
        if (! Uuid::isValid($key) || ! Uuid::isValid($id)) {
            throw new \DomainException('UUID request and Idempotency-Key required.');
        }
        $result = DB::connection('platform')->transaction(function () use ($id, $actor, $key, $correlation, $ip) {
            $r = $this->requests->lockForUpdate($id);
            if (! $r || ! Registry::contains($r->config_key) || $r->maker_id === $actor) {
                throw new \DomainException('Matching policy and distinct checker required.');
            }
            $this->lock('security-policy:'.$r->config_key);
            $value = Registry::unwrap($r->proposed_value);
            Registry::validate($r->config_key, $value);
            $this->validateRoles($r->config_key, $value);
            if ($r->config_key === 'security.admin.ip_allowlist' && ! Registry::allowsIp($ip, $value)) {
                throw new \DomainException('The proposed allowlist excludes the approving administrator address.');
            }
            $applied = DB::connection('platform')->table('platform.configuration_values')->where('change_request_id', $id)->whereIn('status', ['active', 'archived'])->exists();
            if ($applied) {
                return ['id' => $id, 'status' => 'active', 'key' => $r->config_key];
            }
            if ($r->status === ChangeRequestStatus::PendingApproval) {
                $a = $this->changes->approve(new ApproveChangeRequestDTO($id, $actor, $key, $correlation));
                if (($a['id'] ?? null) !== $id) {
                    throw new \DomainException('Approval key conflicts with another request.');
                }
            } elseif ($r->status !== ChangeRequestStatus::Approved) {
                throw new \DomainException('Policy request is not approved.');
            }
            $draft = DB::connection('platform')->table('platform.configuration_values')->where('change_request_id', $id)->where('status', 'draft')->lockForUpdate()->first();
            if (! $draft) {
                throw new \DomainException('Approved policy draft not found.');
            }
            $currentVersion = DB::connection('platform')->table('platform.configuration_values')->where('config_key', $r->config_key)->where('status', 'active')->max('version');
            if ($currentVersion !== null && $currentVersion >= $draft->version) {
                throw new \DomainException('A newer policy is already active. Submit a fresh proposal.');
            }
            DB::connection('platform')->table('platform.configuration_values')->where('config_key', $r->config_key)->where('status', 'active')->update(['status' => 'archived']);
            DB::connection('platform')->table('platform.configuration_values')->where('id', $draft->id)->update(['status' => 'active', 'last_updated_by' => $actor, 'updated_at' => now()]);
            DB::connection('platform')->table('platform.configuration_versions')->insert(['id' => (string) Uuid::uuid7(), 'config_key' => $r->config_key, 'domain' => $r->domain->value, 'event_type' => 'activated', 'value' => $draft->config_value, 'previous_value' => $r->previous_value === null ? null : json_encode($r->previous_value), 'maker_id' => $r->maker_id, 'checker_id' => $actor, 'actor_id' => $actor, 'change_request_id' => $id, 'occurred_at' => now(), 'created_at' => now()]);
            $this->audit->record('security.policy.activated', 'security_policy', $id, $actor, $correlation, $r->previous_value ?? [], ['key' => $r->config_key, 'value' => $value]);

            return ['id' => $id, 'status' => 'active', 'key' => $r->config_key];
        });
        $this->config->invalidate($result['key']);

        return $result;
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function validateRoles(string $key, mixed $value): void
    {
        if ($key === 'auth.mfa.role_requirements') {
            foreach (array_keys($value) as $slug) {
                if (! $this->roles->findBySlug((string) $slug)) {
                    throw new \DomainException('Unknown role in MFA policy.');
                }
            }
        }
    }

    private function lock(string $key): void
    {
        DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$key]);
    }
}
