<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Controllers;

use App\Modules\Funding\Application\Services\BankNotificationService;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use App\Modules\Funding\Infrastructure\Http\Resources\FundingTransactionResource;
use App\Modules\Funding\Infrastructure\Models\FundingTransactionModel;
use App\Modules\Ledger\Application\Services\LedgerAccountService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** An explicit development bank simulator, never a production confirmation path. */
final class DevMockBankController extends Controller
{
    public function complete(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $actor = (string) $request->attributes->get('identity.user_id');
        $tx = FundingTransactionModel::where('id', $id)->where('customer_id', $actor)->firstOrFail();
        abort_unless($tx->funding_method === FundingMethod::BankTransfer, 422, 'This simulator accepts bank transfers only.');
        if ($tx->status === FundingStatus::Completed) return response()->json(['data' => new FundingTransactionResource($tx)]);
        abort_unless($tx->status === FundingStatus::AwaitingMatch, 422, 'Confirm the payment with your PIN first.');
        $correlation = $request->header('X-Correlation-ID', (string) Str::uuid7());
        $float = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'olekard.local/mock-bank/float/'.$tx->pocket_currency_code);
        DB::connection('platform')->transaction(function () use ($tx, $actor, $correlation, $float): void {
            app(LedgerAccountService::class)->registerAccount($float, 'asset', $tx->pocket_currency_code,
                $float, 'system_float', $actor, $correlation);
            app(BankNotificationService::class)->handle([
                'bank_notification_id' => 'dev-mock-bank:'.$tx->id,
                'bank_reference_code' => $tx->bank_reference_code, 'amount' => $tx->gross_amount,
                'currency_code' => $tx->pocket_currency_code, 'correlation_id' => $correlation,
                'raw_payload' => ['development_mock_bank' => true],
                // Trusted server-only fixture; external callback request bodies cannot set this.
                'float_account_reference' => $float,
            ]);
        });
        return response()->json(['data' => new FundingTransactionResource($tx->fresh())]);
    }
}
