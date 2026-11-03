<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Controllers;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\AgentFloat\Domain\Enums\FloatTransactionType;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatTransactionModel;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AgentDashboardController extends Controller
{
    public function __construct(private readonly LedgerModuleContract $ledger) {}

    /**
     * Return the dashboard for the authenticated active agent. The agent id is
     * derived from Identity and is never accepted from the request.
     */
    public function show(Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('identity.user_id');
        $agent = AgentModel::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if ($agent === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AGENT_NOT_FOUND', 'message' => 'Active agent profile not found.'],
            ], 404);
        }

        $floatWallet = AgentFloatWalletModel::query()->where('agent_id', $agent->id)->first();
        $limits = FloatLimitModel::query()->where('agent_id', $agent->id)->first();

        if ($floatWallet === null || $limits === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FLOAT_CONFIGURATION_NOT_FOUND', 'message' => 'Agent float configuration is unavailable.'],
            ], 404);
        }

        if ($limits->low_threshold_is_tbd || $limits->low_float_threshold === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FLOAT_THRESHOLD_NOT_CONFIGURED', 'message' => 'Agent float threshold is not configured.'],
            ], 503);
        }

        $today = now();
        $transactions = FloatTransactionModel::query()
            ->where('agent_id', $agent->id)
            ->where('status', 'completed')
            ->whereIn('type', [FloatTransactionType::CashIn->value, FloatTransactionType::CashOut->value])
            ->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->selectRaw('type, COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('type')
            ->get()
            ->keyBy(static fn (FloatTransactionModel $transaction): string => $transaction->type instanceof \BackedEnum
                    ? $transaction->type->value
                    : (string) $transaction->type);

        $history = $this->ledger->getAccountHistory($floatWallet->ledger_account_reference, 1, 1);
        $lastPostedAt = $history['data'][0]['posted_at'] ?? null;

        return response()->json([
            'float_balance_minor_units' => $this->ledger->getBalance($floatWallet->ledger_account_reference),
            'float_currency_code' => $limits->currency_code,
            'low_float_threshold_minor_units' => (int) $limits->low_float_threshold,
            'today_cash_in_count' => (int) ($transactions[FloatTransactionType::CashIn->value]->transaction_count ?? 0),
            'today_cash_in_amount_minor_units' => (int) ($transactions[FloatTransactionType::CashIn->value]->total_amount ?? 0),
            'today_cash_out_count' => (int) ($transactions[FloatTransactionType::CashOut->value]->transaction_count ?? 0),
            'today_cash_out_amount_minor_units' => (int) ($transactions[FloatTransactionType::CashOut->value]->total_amount ?? 0),
            'updated_at' => $lastPostedAt ?? $floatWallet->created_at?->toIso8601String(),
        ]);
    }
}
