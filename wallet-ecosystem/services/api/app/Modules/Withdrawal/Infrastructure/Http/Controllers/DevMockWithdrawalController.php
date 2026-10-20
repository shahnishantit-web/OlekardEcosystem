<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Http\Controllers;

use App\Modules\Withdrawal\Application\Jobs\WithdrawalPayoutJob;
use App\Modules\Withdrawal\Application\Services\WithdrawalCallbackService;
use App\Modules\Withdrawal\Domain\Contracts\PayoutProviderAdapter;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use App\Modules\Withdrawal\Infrastructure\Adapters\MockPayoutProviderAdapter;
use App\Modules\Withdrawal\Infrastructure\Http\Resources\WithdrawalTransactionResource;
use App\Modules\Withdrawal\Infrastructure\Models\WithdrawalTransactionModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DevMockWithdrawalController
{
    public function complete(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless(app(PayoutProviderAdapter::class) instanceof MockPayoutProviderAdapter, 422);
        $actor = (string) $request->attributes->get('identity.user_id');
        return DB::connection('platform')->transaction(function () use ($id, $actor) {
            $tx = WithdrawalTransactionModel::whereKey($id)->where('customer_id', $actor)->lockForUpdate()->firstOrFail();
            if ($tx->status !== WithdrawalStatus::Completed) {
                abort_unless($tx->status === WithdrawalStatus::Processing, 422, 'Confirm with your PIN first.');
                if ($tx->provider_reference_id === null) {
                    app()->call([new WithdrawalPayoutJob($id), 'handle']);
                    $tx->refresh();
                }
                app(WithdrawalCallbackService::class)->processCallback('mock', [
                    'provider_reference_id' => $tx->provider_reference_id,
                    'status' => 'success', 'amount' => $tx->net_payout_amount,
                    'currency' => $tx->pocket_currency_code,
                ], 'mock-valid-sig');
            }
            return response()->json(['data' => new WithdrawalTransactionResource($tx->fresh())]);
        });
    }
}
