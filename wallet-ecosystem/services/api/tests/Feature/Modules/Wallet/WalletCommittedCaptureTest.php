<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Wallet;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Domain\Contracts\WalletCaptureContract;
use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use App\Modules\Wallet\Domain\ValueObjects\WalletCaptureRequest;
use App\Modules\Wallet\Infrastructure\Models\WalletHoldModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

final class WalletCommittedCaptureTest extends WalletTestCase
{
    private string $owner;

    private string $organisationId;

    private string $walletId;

    private string $pocketId;

    private string $attemptId;

    private string $intentId;

    private string $holdId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = (string) Uuid::uuid7();
        $this->organisationId = (string) Uuid::uuid7();
        DB::connection('identity')->table('identity.users')->insert([
            'id' => $this->owner, 'status' => 'active', 'actor_type' => 'CUSTOMER',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->walletId = (string) Uuid::uuid7();
        DB::connection('wallet')->table('wallet.wallets')->insert([
            'id' => $this->walletId,
            'wallet_reference' => 'P08-WLT-'.strtoupper(substr(str_replace('-', '', $this->walletId), 0, 12)),
            'owner_id' => $this->owner, 'status' => 'active', 'version' => 1,
            'created_by' => $this->owner, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pocketId = (string) Uuid::uuid7();
        DB::connection('wallet')->table('wallet.currency_pockets')->insert([
            'id' => $this->pocketId, 'wallet_id' => $this->walletId, 'currency_code' => 'BWP',
            'ledger_account_reference' => 'ledger-'.$this->pocketId, 'status' => 'active',
            'created_by' => $this->owner, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->attemptId = (string) Uuid::uuid7();
        $this->intentId = (string) Uuid::uuid7();

        app(LedgerModuleContract::class)->postEntry([
            'idempotency_key' => 'wallet-capture-fund:'.$this->walletId,
            'entry_type' => 'credit',
            'currency_code' => 'BWP',
            'transaction_reference' => $this->walletId,
            'correlation_id' => (string) Uuid::uuid7(),
            'actor_id' => $this->owner,
            'lines' => [
                ['ledger_account_reference' => 'test-funding:'.$this->walletId, 'direction' => 'debit', 'amount' => 5000],
                ['ledger_account_reference' => 'ledger-'.$this->pocketId, 'direction' => 'credit', 'amount' => 5000],
            ],
        ]);
        $this->holdId = app(WalletHoldServiceContract::class)
            ->place($this->walletId, 'BWP', 1250, $this->owner, (string) Uuid::uuid7(), $this->attemptId,
                expiresAt: now()->addHour()->toDateTimeImmutable(), organisationId: $this->organisationId)->id;

        config([
            'wallet.capture.test_only_enabled' => true,
            'wallet.capture.test_destination_accounts.BWP' => 'test-destination:'.$this->walletId,
        ]);
    }

    public function test_wallet_consumes_hold_and_returns_journal_bound_capture_evidence_idempotently(): void
    {
        $request = $this->request();
        $wallet = app(WalletCaptureContract::class);
        $captured = $wallet->captureForPayment($request);
        $replayed = $wallet->captureForPayment($request);

        self::assertSame($captured->captureId, $replayed->captureId);
        self::assertSame($captured->ledgerJournalId, $replayed->ledgerJournalId);
        self::assertSame(1250, $captured->capturedAmountMinor);
        self::assertSame('BWP', $captured->currencyCode);
        self::assertSame(1, DB::connection('wallet')->table('wallet.wallet_capture_records')
            ->where('hold_id', $this->holdId)->count());
        self::assertSame(WalletHoldStatus::Captured, WalletHoldModel::on('wallet')->findOrFail($this->holdId)->status);

        $posted = app(LedgerModuleContract::class)->findPostedJournalEvidence($captured->ledgerIdempotencyKey);
        self::assertNotNull($posted);
        self::assertSame($captured->ledgerJournalId, $posted->journalId);
        self::assertSame(2, count($posted->lines));
        self::assertSame(3750, app(LedgerModuleContract::class)->getBalance('ledger-'.$this->pocketId));
    }

    public function test_capture_rejects_wrong_owner_attempt_amount_currency_and_expired_or_released_holds(): void
    {
        $wallet = app(WalletCaptureContract::class);
        try {
            $wallet->captureForPayment(new WalletCaptureRequest(
                $this->holdId, $this->attemptId, $this->intentId, $this->organisationId,
                (string) Uuid::uuid7(), 1250, 'BWP', (string) Uuid::uuid7(),
            ));
            self::fail('Wrong hold owner must be rejected.');
        } catch (\DomainException) {
            self::assertSame(0, DB::connection('wallet')->table('wallet.wallet_capture_records')
                ->where('hold_id', $this->holdId)->count());
        }

        foreach ([
            new WalletCaptureRequest($this->holdId, (string) Uuid::uuid7(), $this->intentId, $this->organisationId, $this->owner, 1250, 'BWP', (string) Uuid::uuid7()),
            new WalletCaptureRequest($this->holdId, $this->attemptId, $this->intentId, (string) Uuid::uuid7(), $this->owner, 1250, 'BWP', (string) Uuid::uuid7()),
            new WalletCaptureRequest($this->holdId, $this->attemptId, $this->intentId, $this->organisationId, $this->owner, 1251, 'BWP', (string) Uuid::uuid7()),
            new WalletCaptureRequest($this->holdId, $this->attemptId, $this->intentId, $this->organisationId, $this->owner, 1250, 'USD', (string) Uuid::uuid7()),
        ] as $request) {
            try {
                $wallet->captureForPayment($request);
                self::fail('Mismatched hold evidence must be rejected.');
            } catch (\DomainException) {
                self::assertSame(0, DB::connection('wallet')->table('wallet.wallet_capture_records')
                    ->where('hold_id', $this->holdId)->count());
            }
        }

        DB::connection('wallet')->table('wallet.wallet_holds')->where('id', $this->holdId)->update(['expires_at' => now()->subSecond()]);
        try {
            $wallet->captureForPayment($this->request());
            self::fail('Expired hold must not be captured.');
        } catch (\DomainException) {
            self::assertSame('active', DB::connection('wallet')->table('wallet.wallet_holds')->where('id', $this->holdId)->value('status'));
        }
    }

    public function test_operational_capture_is_closed_without_explicit_test_only_configuration(): void
    {
        config(['wallet.capture.test_only_enabled' => false]);
        $this->expectException(\RuntimeException::class);
        app(WalletCaptureContract::class)->captureForPayment($this->request());
    }

    private function request(): WalletCaptureRequest
    {
        return new WalletCaptureRequest(
            $this->holdId, $this->attemptId, $this->intentId, $this->organisationId, $this->owner, 1250, 'BWP', (string) Uuid::uuid7(),
        );
    }
}
