<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Agent\Domain\Enums\AgentStatus;
use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Domain\Enums\CommissionRuleType;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use App\Modules\Commission\Domain\Events\CommissionCalculatedEvent;
use App\Modules\Commission\Domain\Events\CommissionLedgerPostFailedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionExpenseAccountNotConfiguredException;
use App\Modules\Commission\Infrastructure\Models\CommissionModel;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommissionCalculationService
{
    public function __construct(
        private readonly ProcessedEventRepositoryInterface $processedEvents,
        private readonly LedgerModuleContract $ledger,
        private readonly ConfigurationServiceInterface $config,
        private readonly AuditServiceInterface $audit,
        private readonly EventPublisherInterface $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function calculate(
        string $agentId,
        string $transactionId,
        string $transactionType,
        int    $amount,
        string $currencyCode,
        string $walletId,
        string $correlationId,
    ): ?CommissionModel {
        $agent = AgentModel::find($agentId);
        if ($agent === null || $agent->status === AgentStatus::Suspended) {
            return null;
        }

        $rule = $this->resolveRule($agentId, $transactionType, $currencyCode);
        if ($rule === null) {
            return null;
        }

        $commissionAmount = $this->calculateAmount($amount, $rule);
        if ($commissionAmount === null || $commissionAmount === 0) {
            return null;
        }

        $idempotencyKey = "{$transactionId}:{$transactionType}";

        $existing = CommissionModel::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $expenseAccount = $this->config->get("ledger.commission_expense_account.{$currencyCode}");
        if ($expenseAccount === null) {
            throw new CommissionExpenseAccountNotConfiguredException($currencyCode);
        }

        $payableAccount = "commission_payable:{$agentId}";
        $this->ledger->registerAccount(
            ledgerAccountReference: $payableAccount,
            accountType:            'liability',
            currencyCode:           $currencyCode,
            ownerReference:         $agentId,
            ownerType:              'agent_commission',
        );

        $commissionId = Str::orderedUuid()->toString();
        $journalKey   = Str::orderedUuid()->toString();

        return DB::connection('platform')->transaction(function () use (
            $commissionId, $agentId, $transactionId, $transactionType,
            $commissionAmount, $currencyCode, $rule,
            $idempotencyKey, $journalKey, $correlationId,
            $expenseAccount, $payableAccount,
        ) {
            try {
                $journalId = $this->ledger->postEntry([
                    'idempotency_key'       => $journalKey,
                    'entry_type'            => 'commission',
                    'currency_code'         => $currencyCode,
                    'transaction_reference' => "commission_accrual:{$commissionId}",
                    'correlation_id'        => $correlationId,
                    'description'           => "Commission accrual for {$transactionType} transaction {$transactionId}",
                    'lines'                 => [
                        ['ledger_account_reference' => $expenseAccount, 'direction' => 'debit',  'amount' => $commissionAmount],
                        ['ledger_account_reference' => $payableAccount,  'direction' => 'credit', 'amount' => $commissionAmount],
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->events->publish(new CommissionLedgerPostFailedEvent(
                    commissionId:    $commissionId,
                    agentId:         $agentId,
                    transactionId:   $transactionId,
                    transactionType: $transactionType,
                    amount:          $commissionAmount,
                    currencyCode:    $currencyCode,
                    errorMessage:    $e->getMessage(),
                    correlationId:   $correlationId,
                ));
                return null;
            }

            try {
                $commission = CommissionModel::create([
                    'id'               => $commissionId,
                    'agent_id'         => $agentId,
                    'transaction_id'   => $transactionId,
                    'transaction_type' => $transactionType,
                    'amount'           => $commissionAmount,
                    'currency_code'    => $currencyCode,
                    'rule_id'          => $rule->id,
                    'status'           => CommissionStatus::Pending->value,
                    'journal_id'       => $journalId,
                    'idempotency_key'  => $idempotencyKey,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return CommissionModel::where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            $this->audit->record(new AuditEventData(
                action:        'commission.accrued',
                actorType:     'SYSTEM',
                resourceType:  'commission',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $commissionId,
                metadata:      ['transaction_id' => $transactionId, 'transaction_type' => $transactionType],
            ));

            $this->events->publish(new CommissionCalculatedEvent(
                commissionId:    $commissionId,
                agentId:         $agentId,
                transactionId:   $transactionId,
                transactionType: $transactionType,
                amount:          $commissionAmount,
                currencyCode:    $currencyCode,
                ruleId:          $rule->id,
                journalId:       $journalId,
                correlationId:   $correlationId,
            ));

            $this->metrics->increment('commission.calculated', [
                'transaction_type' => $transactionType,
                'currency'         => $currencyCode,
            ]);

            return $commission;
        });
    }

    private function resolveRule(string $agentId, string $transactionType, string $currencyCode): ?CommissionRuleModel
    {
        $agent = AgentModel::find($agentId);
        $today = now()->toDateString();

        $query = CommissionRuleModel::where('status', 'active')
            ->where('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->where(fn ($q) => $q->where('applies_to', $transactionType)->orWhere('applies_to', 'both'));

        $agentTier = $agent?->agent_tier;

        if ($agentTier !== null) {
            $tierRule = (clone $query)
                ->where('agent_tier', $agentTier)
                ->where(fn ($q) => $this->applyVolumeThreshold($q, $agentId))
                ->orderByDesc('volume_threshold')
                ->orderByDesc('created_at')
                ->first();

            if ($tierRule !== null) {
                return $tierRule;
            }
        }

        return $query
            ->whereNull('agent_tier')
            ->orderByDesc('created_at')
            ->first();
    }

    private function applyVolumeThreshold(\Illuminate\Database\Eloquent\Builder $q, string $agentId): \Illuminate\Database\Eloquent\Builder
    {
        $monthlyVolume = $this->calculateMonthlyVolume($agentId);
        return $q->where(fn ($sq) => $sq->whereNull('volume_threshold')->orWhere('volume_threshold', '<=', $monthlyVolume));
    }

    public function calculateMonthlyVolume(string $agentId): int
    {
        return (int) CommissionModel::where('agent_id', $agentId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('status', '!=', CommissionStatus::Reversed->value)
            ->sum('amount');
    }

    private function calculateAmount(int $transactionAmount, CommissionRuleModel $rule): ?int
    {
        $amount = match ($rule->rule_type) {
            CommissionRuleType::Flat       => $rule->rate,
            CommissionRuleType::Percentage => (int) floor($transactionAmount * $rule->rate / 10000),
        };

        if ($amount === null || $amount <= 0) {
            return null;
        }

        if ($rule->min_amount !== null && $amount < $rule->min_amount) {
            $amount = $rule->min_amount;
        }
        if ($rule->max_amount !== null && $amount > $rule->max_amount) {
            $amount = $rule->max_amount;
        }

        return $amount;
    }
}
