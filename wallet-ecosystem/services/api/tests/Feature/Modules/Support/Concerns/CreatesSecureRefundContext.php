<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Support\Concerns;

use App\Modules\Identity\Domain\Services\TokenService;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Transaction\Infrastructure\Models\TransactionProjectionModel;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Ramsey\Uuid\Uuid;

/** Legacy financial regressions now provide actual original facts and valid scoped MFA. */
trait CreatesSecureRefundContext
{
    private function secureRefundContext(string $uri, array $data, array $headers): array
    {
        if (! preg_match('~/v1/disputes/([a-f0-9-]+)/(?:refund|confirm-refund)$~', $uri, $match) || ! isset($headers['X-Test-User-Id'])) {
            return [$data, $headers];
        }
        $d = DisputeModel::findOrFail($match[1]);
        if (! TransactionProjectionModel::find($d->transaction_id)) {
            TransactionProjectionModel::create(['id' => $d->transaction_id, 'reference' => 'TEST-'.bin2hex(random_bytes(8)), 'type' => 'transfer', 'status' => 'completed', 'amount' => 10000, 'currency_code' => $d->currency_code ?? 'KES', 'direction' => 'debit', 'wallet_id' => (string) Uuid::uuid7(), 'customer_id' => $d->reporter_id, 'source_module' => 'transfer']);
        }
        $wallet = $this->createStub(WalletModuleContract::class);
        $wallet->method('ownerActorId')->willReturn($d->reporter_id);
        $wallet->method('getPocketLedgerAccountRef')->willReturn('wallet:'.$d->reporter_id.':'.$d->currency_code);
        $this->instance(WalletModuleContract::class, $wallet);
        $headers['X-Test-Session-Id'] = 'legacy-refund-test';
        $data['step_up_token'] = app(TokenService::class)->issueStepUpToken($headers['X-Test-User-Id'], 'legacy-refund-test', 'ADMIN', 'support.refund:'.$d->id, 'test-ip', (string) Uuid::uuid7())->stepUpAccessToken;

        return [$data, $headers];
    }

    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        [$data,$headers] = $this->secureRefundContext($uri, $data, $headers);

        return parent::postJson($uri, $data, $headers, $options);
    }

    public function patchJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        [$data,$headers] = $this->secureRefundContext($uri, $data, $headers);

        return parent::patchJson($uri, $data, $headers, $options);
    }
}
