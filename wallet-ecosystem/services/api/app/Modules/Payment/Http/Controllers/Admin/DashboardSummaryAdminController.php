<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * P21 T003: Finance Operations Dashboard summary endpoint.
 *
 * Permission: payment.history.read.platform (enforced via route middleware)
 *
 * Returns today's payment volume by currency, attempt counts by status,
 * and fulfilment backlog count. All queries run on the payment DB connection.
 * No cross-module joins.
 */
final class DashboardSummaryAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $todayStart = now()->utc()->startOfDay()->toIso8601String();
        $todayEnd   = now()->utc()->endOfDay()->toIso8601String();

        $volumeByCurrency = DB::connection('payment')
            ->table('payment.payment_intents')
            ->select('currency_code', DB::raw('SUM(amount_minor) as total_minor'), DB::raw('COUNT(*) as count'))
            ->whereIn('status', ['captured', 'fulfilled'])
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->groupBy('currency_code')
            ->get()
            ->map(fn ($row) => [
                'currency_code' => $row->currency_code,
                'total_minor'   => (int) $row->total_minor,
                'count'         => (int) $row->count,
            ])
            ->values()
            ->all();

        $attemptCounts = DB::connection('payment')
            ->table('payment.payment_intents')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereIn('status', ['created', 'captured', 'fulfilled', 'failed', 'cancelled'])
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Fulfilment backlog: captured intents not yet dispatched for fulfilment
        $backlogCount = DB::connection('payment')
            ->table('payment.payment_intents')
            ->where('status', 'captured')
            ->whereNull('fulfilment_status')
            ->count();

        return response()->json([
            'data' => [
                'volume_by_currency'       => $volumeByCurrency,
                'attempt_counts_by_status' => $attemptCounts,
                'fulfilment_backlog_count'  => (int) $backlogCount,
                'fetched_at'               => now()->utc()->toIso8601String(),
            ],
        ]);
    }
}
