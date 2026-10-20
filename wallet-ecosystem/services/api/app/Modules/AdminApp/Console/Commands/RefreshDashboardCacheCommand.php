<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class RefreshDashboardCacheCommand extends Command
{
    protected $signature = 'adminapp:refresh-dashboard-cache';
    protected $description = 'Refresh AdminApp dashboard metric caches (TTL 300s)';

    private const TTL = 300;

    public function handle(): int
    {
        $cachedAt = now()->toIso8601ZuluString();

        $this->refreshOperational($cachedAt);
        $this->refreshFinancial($cachedAt);
        $this->refreshRisk($cachedAt);

        $this->info('AdminApp dashboard caches refreshed.');

        return self::SUCCESS;
    }

    private function refreshOperational(string $cachedAt): void
    {
        $today = now()->startOfDay();

        $data = [
            'cached_at'                             => $cachedAt,
            'total_users'                           => $this->countValue(fn () => DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->count()),
            'active_users_today'                    => $this->countValue(fn () => DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->where('last_login_at', '>=', $today)->count()),
            'new_registrations_today'               => $this->countValue(fn () => DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->where('created_at', '>=', $today)->count()),
            'pending_kyc_count'                     => $this->countValue(fn () => DB::table('kyc.kyc_cases')->where('status', 'under_review')->count()),
            'approved_kyc_today'                    => $this->countValue(fn () => DB::table('kyc.kyc_cases')->where('status', 'verified')->where('reviewed_at', '>=', $today)->count()),
            'total_transactions_today'              => $this->countValue(fn () => DB::table('transaction.transaction_projections')->where('created_at', '>=', $today)->count()),
            'transaction_volume_today_minor_units'  => (int) $this->queryValue(fn () => DB::table('transaction.transaction_projections')->where('created_at', '>=', $today)->where('status', 'completed')->where('currency_code', 'BWP')->sum('amount')),
            'transaction_currency'                  => 'BWP',
            'active_agents'                         => $this->countValue(fn () => DB::table('agent.agents')->where('status', 'active')->count()),
            'pending_agent_approvals'               => $this->countValue(fn () => DB::table('agent.agents')->where('status', 'pending_approval')->count()),
            'open_disputes'                         => $this->countValue(fn () => DB::table('support.support_tickets')->whereIn('status', ['open', 'in_progress'])->count()),
            'open_fraud_alerts'                     => $this->countValue(fn () => DB::table('risk.risk_alerts')->where('status', 'open')->count()),
        ];

        Redis::setex('adminapp:dashboard:operational', self::TTL, json_encode($data));
    }

    private function refreshFinancial(string $cachedAt): void
    {
        $today = now()->startOfDay();

        $data = [
            'cached_at'                                 => $cachedAt,
            'total_wallet_balance_minor_units'          => $this->walletBalance(),
            'balance_currency'                          => 'BWP',
            'transactions_today_count'                  => $this->countValue(fn () => DB::table('transaction.transaction_projections')->where('created_at', '>=', $today)->count()),
            'transactions_today_volume_minor_units'     => (int) $this->queryValue(fn () => DB::table('transaction.transaction_projections')->where('status', 'completed')->where('currency_code', 'BWP')->where('created_at', '>=', $today)->sum('amount')),
            'failed_transactions_today'                 => $this->countValue(fn () => DB::table('transaction.transaction_projections')->where('status', 'failed')->where('created_at', '>=', $today)->count()),
            'total_funding_today_minor_units'           => (int) $this->queryValue(fn () => DB::table('funding.funding_transactions')->where('status', 'completed')->where('pocket_currency_code', 'BWP')->where('created_at', '>=', $today)->sum('amount')),
            'total_withdrawals_today_minor_units'       => (int) $this->queryValue(fn () => DB::table('withdrawal.withdrawal_transactions')->where('status', 'completed')->where('pocket_currency_code', 'BWP')->where('created_at', '>=', $today)->sum('gross_amount')),
            'total_transfers_today_minor_units'         => (int) $this->queryValue(fn () => DB::table('transfer.transfer_transactions')->where('status', 'completed')->where('currency_code', 'BWP')->where('created_at', '>=', $today)->sum('gross_amount')),
            'total_fees_collected_today_minor_units'    => (int) $this->queryValue(fn () => DB::table('fees.fees')->where('status', 'posted')->where('currency', 'BWP')->where('created_at', '>=', $today)->sum('amount_minor')),
            'total_commissions_paid_today_minor_units'  => (int) $this->queryValue(fn () => DB::table('commission.commission_payouts')->where('status', 'completed')->where('currency_code', 'BWP')->where('executed_at', '>=', $today)->sum('total_amount')),
            'total_float_outstanding_minor_units'       => $this->floatBalance(),
            'pending_settlements_count'                 => $this->countValue(fn () => DB::table('reconciliation.settlement_batches')->whereIn('status', ['pending_approval', 'approved'])->count()),
            'pending_settlements_amount_minor_units'    => (int) round(100 * $this->queryValue(fn () => DB::table('reconciliation.settlement_batches')->whereIn('status', ['pending_approval', 'approved'])->where('currency_code', 'BWP')->sum('net_amount'))),
        ];

        Redis::setex('adminapp:dashboard:financial', self::TTL, json_encode($data));
    }

    private function refreshRisk(string $cachedAt): void
    {
        $today = now()->startOfDay();

        $data = [
            'cached_at'                      => $cachedAt,
            'active_fraud_alerts'            => $this->countValue(fn () => DB::table('risk.risk_alerts')->where('status', 'open')->count()),
            'high_risk_transactions_today'   => $this->countValue(fn () => DB::table('risk.risk_evaluations')->where('risk_level', 'high')->where('evaluated_at', '>=', $today)->count()),
            'aml_flags_today'                => $this->countValue(fn () => DB::table('risk.watchlist_matches')->whereNull('cleared_at')->where('screened_at', '>=', $today)->count()),
            'blocked_users_count'            => $this->countValue(fn () => DB::connection('identity')->table('identity.users')->where('status', 'suspended')->count()),
            'frozen_wallets_count'           => $this->countValue(fn () => DB::table('wallet.wallets')->where('status', 'frozen')->count()),
            'failed_kyc_attempts_today'      => $this->countValue(fn () => DB::table('kyc.kyc_cases')->where('status', 'rejected')->where('reviewed_at', '>=', $today)->count()),
            'suspicious_ip_count_today'      => $this->countValue(fn () => DB::table('platform.audit_events')->where('event_type', 'suspicious_ip')->where('occurred_at', '>=', $today)->distinct()->count('ip_address')),
            'open_support_disputes'          => $this->countValue(fn () => DB::table('support.support_tickets')->whereIn('status', ['open', 'in_progress'])->count()),
            'velocity_breach_alerts_today'   => $this->countValue(fn () => DB::table('risk.risk_alerts')->where('alert_type', 'velocity')->where('created_at', '>=', $today)->count()),
        ];

        Redis::setex('adminapp:dashboard:risk', self::TTL, json_encode($data));
    }

    private function walletBalance(): int
    {
        $ledger = app(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class);
        return DB::connection('wallet')->table('wallet.currency_pockets as p')
            ->join('wallet.wallets as w', 'w.id', '=', 'p.wallet_id')
            ->where('w.status', 'active')->where('p.currency_code', 'BWP')->where('p.status', 'active')
            ->pluck('p.ledger_account_reference')->sum(fn ($ref) => $ledger->getBalance($ref));
    }

    private function floatBalance(): int
    {
        $ledger = app(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class);
        return DB::connection('agent')->table('agent.float_wallets as f')
            ->join('wallet.currency_pockets as p', 'p.wallet_id', '=', 'f.wallet_id')
            ->where('p.currency_code', 'BWP')->where('p.status', 'active')
            ->pluck('f.ledger_account_reference')->sum(fn ($ref) => $ledger->getBalance($ref));
    }

    private function countValue(callable $query): int
    {
        return (int) $query();
    }

    private function queryValue(callable $query): mixed
    {
        return $query() ?? 0;
    }
}
