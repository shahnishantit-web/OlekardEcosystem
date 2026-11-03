<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Payment;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Executes the production Ledger contract and persistence path against PostgreSQL.
 * Account references are test-only and confer no Finance or AD-08 approval.
 */
final class PaymentLedgerPostingRecoveryDatabaseTest extends TestCase
{
    /** @var list<array{actor: string, key: string, debit: string, credit: string}> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $journalId = DB::connection('platform')->table('ledger.journals')
                ->where('idempotency_key', $fixture['key'])->value('id');
            if ($journalId !== null) {
                DB::connection('platform')->table('platform.outbox_events')
                    ->where('aggregate_id', $journalId)->delete();
                DB::connection('platform')->table('platform.audit_events')
                    ->where('resource_id', $journalId)->delete();
                DB::connection('platform')->table('ledger.journal_lines')->where('journal_id', $journalId)->delete();
                DB::connection('platform')->table('ledger.journals')->where('id', $journalId)->delete();
            }
            DB::connection('platform')->table('platform.idempotency_records')
                ->where('actor_id', $fixture['actor'])->where('idempotency_key', $fixture['key'])->delete();
            DB::connection('platform')->table('ledger.ledger_accounts')
                ->whereIn('ledger_account_reference', [$fixture['debit'], $fixture['credit']])->delete();
            Cache::store('idempotency')->forget('idempotency:'.$fixture['actor'].':'.$fixture['key']);
        }

        DB::connection('platform')->statement('DROP TRIGGER IF EXISTS p08_abort_ledger_outbox ON platform.outbox_events');
        DB::connection('platform')->statement('DROP FUNCTION IF EXISTS platform.p08_abort_ledger_outbox()');
        parent::tearDown();
    }

    public function test_real_ledger_post_survives_worker_failure_before_payment_reference_and_replays_once(): void
    {
        $request = $this->postingRequest();
        $ledger = app(LedgerModuleContract::class);

        try {
            // LedgerModuleContract::postEntry runs the real Ledger transaction,
            // including account resolution, journal/lines, outbox, audit, and
            // idempotency lock. The injected exception models worker death after
            // that commit and before any Payment reference persistence.
            $ledger->postEntry($request);
            throw new \RuntimeException('fault injection: worker stopped after Ledger commit');
        } catch (\RuntimeException $exception) {
            self::assertSame('fault injection: worker stopped after Ledger commit', $exception->getMessage());
        }

        $journalId = $ledger->postEntry($request);
        $evidence = $ledger->findPostedJournalEvidence($request['idempotency_key']);

        self::assertNotNull($evidence);
        self::assertSame($journalId, $evidence->journalId);
        self::assertSame($request['transaction_reference'], $evidence->transactionReference);
        self::assertSame($request['currency_code'], $evidence->currencyCode);
        self::assertSame(1, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', $request['idempotency_key'])->count());
        self::assertSame(2, DB::connection('platform')->table('ledger.journal_lines')
            ->where('journal_id', $journalId)->count());
        self::assertSame(1, DB::connection('platform')->table('platform.outbox_events')
            ->where('aggregate_id', $journalId)->where('event_name', 'Ledger.Entry.Posted')->count());
    }

    public function test_real_ledger_transaction_rolls_back_journal_and_outbox_when_outbox_insert_fails(): void
    {
        $request = $this->postingRequest();
        DB::connection('platform')->unprepared(<<<'SQL'
            CREATE FUNCTION platform.p08_abort_ledger_outbox() RETURNS trigger AS $body$
            BEGIN
                IF NEW.event_name = 'Ledger.Entry.Posted' THEN
                    RAISE EXCEPTION 'P08 test fault: outbox insert';
                END IF;
                RETURN NEW;
            END;
            $body$ LANGUAGE plpgsql
            SQL
        );
        DB::connection('platform')->statement(<<<'SQL'
            CREATE TRIGGER p08_abort_ledger_outbox
            BEFORE INSERT ON platform.outbox_events
            FOR EACH ROW EXECUTE FUNCTION platform.p08_abort_ledger_outbox()
            SQL
        );

        try {
            app(LedgerModuleContract::class)->postEntry($request);
            self::fail('The injected outbox failure must abort posting.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('P08 test fault: outbox insert', $exception->getMessage());
        }

        self::assertSame(0, DB::connection('platform')->table('ledger.journals')
            ->where('idempotency_key', $request['idempotency_key'])->count());
        self::assertSame(0, DB::connection('platform')->table('platform.idempotency_records')
            ->where('actor_id', $this->fixtures[array_key_last($this->fixtures)]['actor'])
            ->where('idempotency_key', $request['idempotency_key'])->count());
        self::assertSame(0, DB::connection('platform')->table('platform.outbox_events')
            ->where('event_name', 'Ledger.Entry.Posted')
            ->where('correlation_id', $request['correlation_id'])->count());
    }

    /** @return array<string, mixed> */
    private function postingRequest(): array
    {
        $suffix = (string) Uuid::uuid7();
        $fixture = [
            'actor' => (string) Uuid::uuid7(),
            'key' => 'p08-ledger-db-test:'.$suffix,
            'debit' => 'p08-test-debit:'.$suffix,
            'credit' => 'p08-test-credit:'.$suffix,
        ];
        $this->fixtures[] = $fixture;

        return [
            'idempotency_key' => $fixture['key'],
            'entry_type' => 'debit',
            'currency_code' => 'BWP',
            'transaction_reference' => (string) Uuid::uuid7(),
            'correlation_id' => (string) Uuid::uuid7(),
            'actor_id' => $fixture['actor'],
            'description' => 'P08 isolated Ledger transaction verification',
            'lines' => [
                ['ledger_account_reference' => $fixture['debit'], 'direction' => 'debit', 'amount' => 1250],
                ['ledger_account_reference' => $fixture['credit'], 'direction' => 'credit', 'amount' => 1250],
            ],
        ];
    }
}
