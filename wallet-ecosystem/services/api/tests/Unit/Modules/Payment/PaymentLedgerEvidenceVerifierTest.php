<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payment;

use App\Modules\Identity\Domain\Contracts\ServiceActor;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Ledger\Domain\ValueObjects\PostedJournalEvidence;
use App\Modules\Payment\Application\Services\LedgerPaymentEvidenceVerifier;
use App\Modules\Payment\Application\Services\PaymentConfirmationEvidenceService;
use PHPUnit\Framework\TestCase;

final class PaymentLedgerEvidenceVerifierTest extends TestCase
{
    public function test_accepts_exact_posted_debit_and_credit_correspondence(): void
    {
        $evidence = new PostedJournalEvidence('journal-1', 'posted', 'debit', 'intent-1', 'payment:intent-1:ack', 'BWP', [
            ['account_reference' => 'credit', 'direction' => 'credit', 'amount_minor' => 1234, 'currency_code' => 'BWP'],
            ['account_reference' => 'debit', 'direction' => 'debit', 'amount_minor' => 1234, 'currency_code' => 'BWP'],
        ]);
        self::assertTrue((new LedgerPaymentEvidenceVerifier())->matches(
            $evidence, 'journal-1', 'intent-1', 'BWP', 1234, 'payment:intent-1:ack', 'debit', 'credit',
        ));
    }

    public function test_rejects_unposted_journal_and_individual_line_mismatches(): void
    {
        $verifier = new LedgerPaymentEvidenceVerifier();
        $base = [
            ['account_reference' => 'debit', 'direction' => 'debit', 'amount_minor' => 1234, 'currency_code' => 'BWP'],
            ['account_reference' => 'credit', 'direction' => 'credit', 'amount_minor' => 1234, 'currency_code' => 'BWP'],
        ];
        $unposted = new PostedJournalEvidence('j', 'pending', 'debit', 'i', 'k', 'BWP', $base);
        self::assertFalse($verifier->matches($unposted, 'j', 'i', 'BWP', 1234, 'k', 'debit', 'credit'));

        $wrongLine = $base;
        $wrongLine[1]['amount_minor'] = 1233;
        $mismatch = new PostedJournalEvidence('j', 'posted', 'debit', 'i', 'k', 'BWP', $wrongLine);
        self::assertFalse($verifier->matches($mismatch, 'j', 'i', 'BWP', 1234, 'k', 'debit', 'credit'));
    }

    public function test_rejects_journal_id_transaction_reference_and_currency_mismatches(): void
    {
        $verifier = new LedgerPaymentEvidenceVerifier();
        $lines = [
            ['account_reference' => 'debit', 'direction' => 'debit', 'amount_minor' => 1234, 'currency_code' => 'BWP'],
            ['account_reference' => 'credit', 'direction' => 'credit', 'amount_minor' => 1234, 'currency_code' => 'BWP'],
        ];
        $evidence = new PostedJournalEvidence('j', 'posted', 'debit', 'i', 'k', 'BWP', $lines);
        self::assertFalse($verifier->matches($evidence, 'other-journal', 'i', 'BWP', 1234, 'k', 'debit', 'credit'));
        self::assertFalse($verifier->matches($evidence, 'j', 'other-intent', 'BWP', 1234, 'k', 'debit', 'credit'));
        self::assertFalse($verifier->matches($evidence, 'j', 'i', 'USD', 1234, 'k', 'debit', 'credit'));
    }

    public function test_confirmation_evidence_requires_events_service_and_exact_scoped_scope(): void
    {
        $service = new PaymentConfirmationEvidenceService($this->createMock(LedgerModuleContract::class));
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->confirmationEvidence(new ServiceActor('other', 'SERVICE', ['payment.confirmation.read:org-1:paid_event_ticket'], null),
            'intent', 'org-1', 'paid_event_ticket', 'order-1');
    }
}
