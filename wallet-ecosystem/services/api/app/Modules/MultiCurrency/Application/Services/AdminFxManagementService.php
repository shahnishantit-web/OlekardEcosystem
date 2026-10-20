<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateProviderInterface;
use App\Modules\MultiCurrency\Domain\Enums\FxRateStatus;
use App\Modules\MultiCurrency\Domain\Exceptions\FxProviderUnavailableException;
use App\Modules\MultiCurrency\Http\Resources\FxRateResource;
use App\Modules\MultiCurrency\Infrastructure\Models\FxRateModel;
use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\Services\ConfigurationChangeRequestService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AdminFxManagementService
{
    public function __construct(private readonly CurrencyRepositoryInterface $currencies, private readonly ConfigurationChangeRequestService $changes, private readonly ConfigurationChangeRequestRepositoryInterface $requests, private readonly ConfigurationServiceInterface $config, private readonly FxRateAdminService $rates, private readonly FxRateProviderInterface $provider, private readonly AuditServiceInterface $audit) {}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function registerCurrency(array $data, string $actor, string $correlation): array
    {
        return DB::connection('platform')->transaction(function () use ($data, $actor, $correlation) {
            FxRateAdminService::lock('currency:'.$data['code']);
            if ($this->currencies->findByCode($data['code']) || $data['is_active'] || (int) $data['decimal_places'] !== 2) {
                throw new \DomainException('Create a new inactive currency with supported precision 2; enable it through the approved status workflow. Existing currencies must use PATCH.');
            }
            $c = $this->currencies->upsert($data);
            $this->record('registered', $c->code, $actor, $correlation, $data);

            return $this->currency($c->code);
        });
    }

    /** @return array<string,mixed> */
    public function currency(string $code): array
    {
        $c = $this->currencies->findByCode(strtoupper($code));
        if (! $c) {
            throw new \DomainException('Currency not found.');
        }
        $base = $this->config->getOrDefault('multi_currency.base_currency', null);
        if (is_array($base)) {
            $base = $base['value'] ?? null;
        }
        $base = is_string($base) && preg_match('/^[A-Z]{3}$/D', $base) ? $base : null;
        $pending = $this->requests->findPendingByKey('financial_policy.multi_currency.currency.'.$c->code);

        return ['code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol, 'decimal_places' => $c->decimal_places, 'is_active' => $c->is_active, 'is_base' => $base === null ? null : $base === $c->code, 'base_currency' => $base, 'pending_is_active' => $pending?->proposed_value['is_active'] ?? null, 'pending_change_request_id' => $pending?->id];
    }

    /** @return array<int,array<string,mixed>> */
    public function currencies(): array
    {
        return $this->currencies->listAll()->map(fn ($c) => $this->currency($c->code))->all();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function updateCurrency(string $code, array $data, string $actor, string $key, string $correlation): array
    {
        return DB::connection('platform')->transaction(function () use ($code, $data, $actor, $key, $correlation) {
            $code = strtoupper($code);
            FxRateAdminService::lock('fx-idem:'.$key);
            FxRateAdminService::lock('currency:'.$code);
            $c = $this->currencies->findByCode($code);
            if (! $c) {
                throw new \DomainException('Currency not found.');
            }
            if (isset($data['decimal_places']) && (int) $data['decimal_places'] !== $c->decimal_places) {
                throw new \DomainException('Currency precision is immutable through this API; changing it requires a separately approved financial migration.');
            }
            $financial = array_key_exists('is_active', $data);
            if ($financial) {
                if (! Uuid::isValid($key)) {
                    throw new \DomainException('UUID Idempotency-Key required for currency status changes.');
                }
                $configKey = 'financial_policy.multi_currency.currency.'.$code;
                $proposal = ['code' => $code, 'is_active' => (bool) $data['is_active']];
                $old = $this->requests->findByIdempotencyKey($key);
                if ($old && ($old->maker_id !== $actor || $old->config_key !== $configKey || $old->proposed_value !== $proposal)) {
                    throw new \DomainException('Idempotency key conflicts with another request.');
                }
                $r = $this->changes->submit(new SubmitChangeRequestDTO($configKey, $proposal, $actor, $key, $correlation));

                return array_merge($this->currency($code), ['pending_change_request_id' => $r['id']]);
            }
            $c = $this->currencies->upsert(array_merge(['code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol, 'decimal_places' => $c->decimal_places, 'is_active' => $c->is_active], $data));
            $this->record('metadata_updated', $c->code, $actor, $correlation, $data);

            return $this->currency($code);
        });
    }

    /** @return array<string,mixed> */
    public function activateCurrency(string $code, string $requestId, string $actor, string $key, string $correlation): array
    {
        return DB::connection('platform')->transaction(function () use ($code, $requestId, $actor, $key, $correlation) {
            FxRateAdminService::lock('currency:'.$code);
            $r = $this->requests->lockForUpdate($requestId);
            if (! $r || $r->config_key !== 'financial_policy.multi_currency.currency.'.$code || ($r->proposed_value['code'] ?? null) !== $code || $r->maker_id === $actor) {
                throw new \DomainException('Distinct checker and matching currency request required.');
            }
            if (! Uuid::isValid($key)) {
                throw new \DomainException('UUID Idempotency-Key required.');
            }
            if (DB::connection('platform')->table('multi_currency.currency_change_applications')->where('change_request_id', $requestId)->exists()) {
                return $this->currency($code);
            }
            $this->approve($requestId, $actor, $key, $correlation);
            $c = $this->currencies->findByCode($code);
            if (! $c) {
                throw new \DomainException('Currency not found.');
            }
            $this->currencies->upsert(['code' => $code, 'name' => $c->name, 'symbol' => $c->symbol, 'decimal_places' => $c->decimal_places, 'is_active' => (bool) $r->proposed_value['is_active']]);
            DB::connection('platform')->table('multi_currency.currency_change_applications')->insert(['change_request_id' => $requestId, 'currency_code' => $code, 'checker_id' => $actor, 'applied_at' => now()]);
            $this->record('status_activated', $code, $actor, $correlation, $r->proposed_value);

            return $this->currency($code);
        });
    }

    public function approve(string $id, string $actor, string $key, string $correlation): void
    {
        if (! Uuid::isValid($key)) {
            throw new \DomainException('UUID Idempotency-Key required.');
        }
        $r = $this->requests->lockForUpdate($id);
        if (! $r || $r->maker_id === $actor) {
            throw new \DomainException('A distinct checker is required.');
        }
        $used = $this->requests->findByIdempotencyKey($key);
        if ($used) {
            throw new \DomainException('Approval key cannot reuse a submission key.');
        }
        if ($r->status === ChangeRequestStatus::PendingApproval) {
            $result = $this->changes->approve(new ApproveChangeRequestDTO($id, $actor, $key, $correlation));
            if (($result['id'] ?? null) !== $id || $this->requests->findById($id)?->status !== ChangeRequestStatus::Approved) {
                throw new \DomainException('Approval idempotency key conflicts with another request.');
            }
        } elseif ($r->status !== ChangeRequestStatus::Approved) {
            throw new \DomainException('Request is not approved.');
        }
    }

    /** @param array<string,mixed> $data */
    public function proposeRate(array $data, string $actor, string $key, string $correlation): FxRateModel
    {
        $mode = $data['mode'] ?? 'manual';
        $provider = null;
        $observed = null;
        // Replay provider requests before calling external infrastructure.
        $old = $this->requests->findByIdempotencyKey($key);
        if ($mode === 'provider' && $old) {
            $originalId = $old->proposed_value['fx_rate_id'] ?? null;
            $m = is_string($originalId) && Uuid::isValid($originalId) ? FxRateModel::find($originalId) : null;
            if (! $m || $old->maker_id !== $actor || $m->getAttribute('source') !== 'provider' || $m->from_currency_code !== strtoupper($data['from_currency_code']) || $m->to_currency_code !== strtoupper($data['to_currency_code']) || bccomp($m->spread, $data['spread'], 5) !== 0 || ($old->proposed_value['requested_effective_from'] ?? null) !== (isset($data['effective_from']) ? Carbon::parse($data['effective_from'])->toIso8601String() : null)) {
                throw new \DomainException('Idempotency key conflicts with another request.');
            }

            return $m;
        }
        if ($mode === 'provider') {
            try {
                $quote = $this->provider->quote(strtoupper($data['from_currency_code']), strtoupper($data['to_currency_code']));
            } catch (\Throwable) {
                throw new FxProviderUnavailableException('FX provider is not configured or unavailable. Provider selection remains pending.');
            }
            $data['rate'] = $quote['rate'];
            $provider = $quote['provider'];
            $observed = $quote['observed_at'];
            $this->validateObservation($data['rate'], $provider, $observed);
        }

        return $this->rates->submitRateChange($data['from_currency_code'], $data['to_currency_code'], $data['rate'], $data['spread'], $data['effective_from'] ?? now()->toIso8601String(), $actor, $key, $correlation, $mode, $provider, $observed, $data['effective_from'] ?? null);
    }

    public function activateRate(string $id, string $actor, string $key, string $correlation): FxRateModel
    {
        return DB::connection('platform')->transaction(function () use ($id, $actor, $key, $correlation) {
            $m = FxRateModel::whereKey($id)->lockForUpdate()->first();
            if (! $m) {
                throw new \DomainException('Rate not found.');
            }
            $this->approve((string) $m->change_request_id, $actor, $key, $correlation);

            return $this->rates->activateRate($id, $actor, $correlation);
        });
    }

    /** Trusted provider adapter calls this after an authentic observation. Manual active rates are never overwritten. */
    public function ingestProviderRate(string $from, string $to, string $rate, string $provider, string $observedAt, string $correlation): bool
    {
        $this->validateObservation($rate, $provider, $observedAt);

        return DB::connection('platform')->transaction(function () use ($from, $to, $rate, $provider, $observedAt, $correlation) {
            FxRateAdminService::lock('fx-pair:'.$from.'-'.$to);
            $active = FxRateModel::where('from_currency_code', $from)->where('to_currency_code', $to)->where('status', 'active')->first();
            if (! $active || $active->getAttribute('source') !== 'provider' || $active->getAttribute('provider') !== $provider || Carbon::parse($observedAt)->lte($active->getAttribute('observed_at') ?? $active->effective_from)) {
                return false;
            }
            if (! $this->currencies->findByCode($from)?->is_active || ! $this->currencies->findByCode($to)?->is_active) {
                return false;
            }
            $next = $active->replicate();
            $next->id = (string) Uuid::uuid7();
            $next->rate = $rate;
            $next->effective_from = now();
            $next->created_at = now();
            $next->observed_at = Carbon::parse($observedAt);
            $active->status = FxRateStatus::Superseded;
            $active->effective_to = now();
            $active->save();
            $next->save();
            $this->record('provider_updated', $next->id, $next->created_by, $correlation, ['rate' => $rate, 'provider' => $provider]);

            return true;
        });
    }

    private function validateObservation(string $rate, string $provider, string $observed): void
    {
        FxRateAdminService::decimals($rate, '0');
        if (trim($provider) === '' || strlen($provider) > 100 || Carbon::parse($observed)->isFuture()) {
            throw new \DomainException('Invalid provider observation.');
        }
    }

    /** @return array<string,mixed> */
    public function history(string $pair, int $page, int $perPage): array
    {
        if (! preg_match('/^([A-Z]{3})-([A-Z]{3})$/D', $pair, $m) || $m[1] === $m[2]) {
            throw new \DomainException('Pair must be FROM-TO.');
        }
        $q = FxRateModel::where('from_currency_code', $m[1])->where('to_currency_code', $m[2])->whereIn('status', ['active', 'superseded']);
        $h = (clone $q)->orderByDesc('effective_from')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return ['chart' => (clone $q)->where('effective_from', '>=', now()->subDays(30))->orderBy('effective_from')->get()->map(fn ($r) => (new FxRateResource($r))->resolve())->all(), 'history' => collect($h->items())->map(fn ($r) => (new FxRateResource($r))->resolve())->all(), 'pagination' => ['page' => $h->currentPage(), 'last_page' => $h->lastPage(), 'total' => $h->total()]];
    }

    /** @param array<string,mixed> $state */
    private function record(string $action, string $id, string $actor, string $correlation, array $state): void
    {
        $this->audit->record(new AuditEventData(action: 'multi_currency.currency.'.$action, actorId: $actor, actorType: 'ADMIN', resourceType: 'multi_currency_configuration', resourceId: $id, correlationId: $correlation, outcome: 'success', afterState: $state));
    }
}
