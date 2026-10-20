<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\MultiCurrency\Domain\Enums\FxRateStatus;
use App\Modules\MultiCurrency\Infrastructure\Models\FxRateModel;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\Services\ConfigurationChangeRequestService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class FxRateAdminService
{
    public function __construct(private readonly FxRateRepositoryInterface $fxRates, private readonly CurrencyRepositoryInterface $currencies, private readonly ConfigurationChangeRequestService $changes, private readonly ConfigurationChangeRequestRepositoryInterface $requests, private readonly AuditServiceInterface $audit) {}

    public static function lock(string $key): void
    {
        DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    public static function decimals(string $rate, string $spread): void
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/D', $rate) || bccomp($rate, '0', 8) <= 0 || ! preg_match('/^0(?:\.[0-9]{1,5})?$/D', $spread)) {
            throw new \DomainException('Rate must be positive decimal(18,8); spread must be a fraction from 0 inclusive to 1 exclusive with at most 5 decimals.');
        }
    }

    public function submitRateChange(string $fromCurrencyCode, string $toCurrencyCode, string $rate, string $spread, string $effectiveFrom, string $adminId, string $idempotencyKey, string $correlationId, string $source = 'manual', ?string $provider = null, ?string $observedAt = null, ?string $requestedEffectiveFrom = null): FxRateModel
    {
        self::decimals($rate, $spread);
        if (! Uuid::isValid($idempotencyKey)) {
            throw new \DomainException('UUID Idempotency-Key required.');
        }
        $from = strtoupper($fromCurrencyCode);
        $to = strtoupper($toCurrencyCode);
        if ($from === $to || ! $this->currencies->findByCode($from)?->is_active || ! $this->currencies->findByCode($to)?->is_active) {
            throw new \DomainException('Distinct active supported currencies required.');
        }

        return DB::connection('platform')->transaction(function () use ($from, $to, $rate, $spread, $effectiveFrom, $adminId, $idempotencyKey, $correlationId, $source, $provider, $observedAt, $requestedEffectiveFrom) {
            self::lock('fx-idem:'.$idempotencyKey);
            self::lock('fx-pair:'.$from.'-'.$to);
            $existing = $this->requests->findByIdempotencyKey($idempotencyKey);
            if ($existing) {
                $originalId = $existing->proposed_value['fx_rate_id'] ?? null;
                $old = is_string($originalId) && Uuid::isValid($originalId) ? $this->fxRates->findById($originalId) : null;
                if (! $old || $existing->maker_id !== $adminId || $old->from_currency_code !== $from || $old->to_currency_code !== $to || bccomp($old->rate, $rate, 8) !== 0 || bccomp($old->spread, $spread, 5) !== 0 || $old->getAttribute('source') !== $source || ($existing->proposed_value['requested_effective_from'] ?? null) !== ($requestedEffectiveFrom === null ? null : Carbon::parse($requestedEffectiveFrom)->toIso8601String())) {
                    throw new \DomainException('Idempotency key conflicts with another request.');
                }

                return $old;
            }
            $id = (string) Uuid::uuid7();
            $request = $this->changes->submit(new SubmitChangeRequestDTO('multi_currency.fx_rate.'.$from.'_'.$to, ['fx_rate_id' => $id, 'rate' => $rate, 'spread' => $spread, 'source' => $source, 'provider' => $provider, 'observed_at' => $observedAt, 'initial_effective_from' => Carbon::parse($effectiveFrom)->toIso8601String(), 'requested_effective_from' => $requestedEffectiveFrom === null ? null : Carbon::parse($requestedEffectiveFrom)->toIso8601String()], $adminId, $idempotencyKey, $correlationId));
            $model = new FxRateModel;
            $model->forceFill(['id' => $id, 'from_currency_code' => $from, 'to_currency_code' => $to, 'rate' => $rate, 'spread' => $spread, 'effective_from' => Carbon::parse($effectiveFrom), 'status' => FxRateStatus::PendingApproval, 'created_by' => $adminId, 'change_request_id' => $request['id'], 'source' => $source, 'provider' => $provider, 'observed_at' => $observedAt]);
            $this->fxRates->save($model);
            $this->record('submitted', $model, $adminId, $correlationId);

            return $model;
        });
    }

    public function activateRate(string $rateId, string $checkerId, string $correlationId): FxRateModel
    {
        return DB::connection('platform')->transaction(function () use ($rateId, $checkerId, $correlationId) {
            $model = FxRateModel::whereKey($rateId)->lockForUpdate()->first();
            if (! $model) {
                throw new \DomainException('FX rate not found.');
            }
            self::lock('fx-pair:'.$model->from_currency_code.'-'.$model->to_currency_code);
            $request = $this->requests->lockForUpdate((string) $model->change_request_id);
            if (! $request || ! in_array($request->status, [ChangeRequestStatus::Approved], true) || $request->maker_id === $checkerId || $model->created_by === $checkerId || ($request->proposed_value['fx_rate_id'] ?? null) !== $model->id || $request->config_key !== 'multi_currency.fx_rate.'.$model->from_currency_code.'_'.$model->to_currency_code || $request->maker_id !== $model->created_by || bccomp((string) ($request->proposed_value['rate'] ?? '0'), $model->rate, 8) !== 0 || bccomp((string) ($request->proposed_value['spread'] ?? '-1'), $model->spread, 5) !== 0 || ($request->proposed_value['source'] ?? 'manual') !== $model->getAttribute('source')) {
                throw new \DomainException('A distinct checker and approved matching E28 change request are required.');
            }
            if (($request->proposed_value['provider'] ?? null) !== $model->getAttribute('provider') || ($model->status === FxRateStatus::PendingApproval && isset($request->proposed_value['initial_effective_from']) && Carbon::parse($request->proposed_value['initial_effective_from'])->ne($model->effective_from))) {
                throw new \DomainException('Approved provider or effective date does not match.');
            }
            if ($model->status === FxRateStatus::Active) {
                return $model;
            }
            if ($model->status !== FxRateStatus::PendingApproval || $model->effective_from->isFuture()) {
                throw new \DomainException('Only pending rates whose effective date has arrived can activate.');
            }
            if (! $this->currencies->findByCode($model->from_currency_code)?->is_active || ! $this->currencies->findByCode($model->to_currency_code)?->is_active) {
                throw new \DomainException('Both currencies must remain active.');
            }
            $this->fxRates->supersedeCurrentRate($model->from_currency_code, $model->to_currency_code);
            $model->status = FxRateStatus::Active;
            $model->approved_by = $checkerId;
            $model->effective_from = now();
            $this->fxRates->save($model);
            $this->record('activated', $model, $checkerId, $correlationId);

            return $model;
        });
    }

    private function record(string $action, FxRateModel $m, string $actor, string $correlation): void
    {
        $this->audit->record(new AuditEventData(action: 'multi_currency.fx_rate.'.$action, actorId: $actor, actorType: 'ADMIN', resourceType: 'fx_rate', resourceId: $m->id, correlationId: $correlation, outcome: 'success', afterState: ['rate' => $m->rate, 'spread' => $m->spread, 'source' => $m->getAttribute('source'), 'status' => $m->status->value]));
    }

    public function listActive(): Collection
    {
        return $this->fxRates->listActive();
    }

    public function listAll(): Collection
    {
        return $this->fxRates->listAll();
    }
}
