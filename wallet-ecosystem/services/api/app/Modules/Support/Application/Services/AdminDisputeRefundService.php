<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Fees\Domain\Contracts\FeesModuleContract;
use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Events\AdminCaseEvent;
use App\Modules\Support\Domain\Events\DisputeRefundedEvent;
use App\Modules\Support\Domain\Events\DisputeRefundPendingConfirmationEvent;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Transaction\Domain\Contracts\SupportRefundContract;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AdminDisputeRefundService
{
    public function __construct(private readonly AdminCaseService $cases, private readonly ActorStepUpVerifierContract $identity, private readonly SupportRefundContract $transactions, private readonly WalletModuleContract $wallets, private readonly LedgerModuleContract $ledger, private readonly FeesModuleContract $fees, private readonly EventPublisherInterface $events, private readonly ConfigurationServiceInterface $configuration) {}

    public function refund(string $id, array $data, string $actor, string $session, string $key, string $correlation): array
    {
        abort_unless($this->identity->verifyStepUpForActor((string) ($data['step_up_token'] ?? ''), 'support.refund:'.$id, $actor, $session), 403, 'Step-up verification required');
        $hash = hash('sha256', json_encode([$data['amount_minor'], $data['reason'], (bool) ($data['confirm'] ?? false), $data['currency'] ?? null], JSON_THROW_ON_ERROR));

        return DB::connection('platform')->transaction(function () use ($id, $data, $actor, $key, $correlation, $hash): array {
            $d = DisputeModel::lockForUpdate()->findOrFail($id);
            $old = DB::connection('platform')->table('support.admin_case_entries')->where('case_id', $id)->where('request_key', $key)->first();
            if ($old) {
                abort_unless($old->actor_id === $actor && hash_equals($old->request_hash, $hash), 409, 'Idempotency key conflicts with original request');

                return $this->cases->detail($id);
            }
            abort_unless($d->status === DisputeStatus::Approved, 409, 'Dispute must be approved');
            $t = $this->transactions->original($d->transaction_id);
            abort_unless($t && $t['status'] === 'completed' && $t['customer_id'] === $d->reporter_id, 422, 'Original transaction is not eligible');
            $amount = (int) $data['amount_minor'];
            $currency = $t['currency_code'];
            abort_if(isset($data['currency']) && $data['currency'] !== $currency, 422, 'Refund currency differs from original');
            DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['support-refund:'.$t['id']]);
            abort_if(isset($data['currency']) && $amount < (int) $t['amount'] && $data['reason'] === 'Approved dispute refund', 422, 'Partial refund justification required');
            abort_unless($amount > 0 && $amount <= (int) $t['remaining_amount'], 422, 'Refund exceeds original amount');
            abort_unless($this->wallets->ownerActorId($t['wallet_id']) === $d->reporter_id, 422, 'Original wallet ownership mismatch');
            $wallet = $this->wallets->getPocketLedgerAccountRef($t['wallet_id'], $currency);
            abort_unless(is_string($wallet) && $wallet !== '', 422, 'Original wallet pocket unavailable');
            $confirm = (bool) ($data['confirm'] ?? false);
            if ($d->refund_pending_confirmation) {
                abort_unless($confirm && $d->refund_initiator_id !== $actor, 409, 'Independent confirmation required');
                abort_unless($amount === $d->refunded_amount_minor && $currency === $d->currency_code && $data['reason'] === $d->partial_refund_justification, 409, 'Confirmation must match pending refund');
            } elseif ($confirm) {
                abort(409, 'No pending refund');
            } elseif (filter_var($this->configuration->getOrDefault('support.dispute.refund.require_second_approval', config('support.dispute.refund.require_second_approval', false)), FILTER_VALIDATE_BOOLEAN)) {
                $d->update(['refund_pending_confirmation' => true, 'refund_initiator_id' => $actor, 'refunded_amount_minor' => $amount, 'currency_code' => $currency, 'partial_refund_justification' => $data['reason']]);
                $this->events->publish(new DisputeRefundPendingConfirmationEvent($id, $actor, now()->toIso8601String(), $correlation));
                $this->cases->entry($id, $actor, 'refund_pending', ['amount_minor' => $amount, 'reason' => $data['reason']], $correlation, $key, $hash);

                return $this->cases->detail($id);
            }
            $journal = $this->ledger->postEntry(['idempotency_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'support.dispute:'.$id.':refund'), 'actor_id' => $actor, 'entry_type' => 'credit', 'currency_code' => $currency, 'transaction_reference' => $d->transaction_id, 'correlation_id' => $correlation, 'description' => 'Compensating dispute refund '.$id, 'lines' => [['ledger_account_reference' => 'liability:disputes:'.$currency, 'direction' => 'debit', 'amount' => $amount], ['ledger_account_reference' => $wallet, 'direction' => 'credit', 'amount' => $amount]]]);
            $this->fees->reverseFee($t['id'], 'revenue:fees:'.$currency, $wallet, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $t['id'].':fee:reversal'), $correlation);
            $refundId = $this->transactions->record($t, $amount, $journal, $id, $correlation);
            $d->update(['status' => DisputeStatus::Refunded, 'refunded_amount_minor' => $amount, 'currency_code' => $currency, 'partial_refund_justification' => $data['reason'], 'refund_ledger_entry_id' => $journal, 'refund_idempotency_key' => $key, 'refund_pending_confirmation' => false, 'refund_initiator_id' => $d->refund_initiator_id ?? $actor, 'refund_confirmed_by' => $actor, 'refund_confirmed_at' => now(), 'dispute_refunded_at' => now()]);
            $eventId = $this->cases->entry($id, $actor, 'refund', ['amount_minor' => $amount, 'currency_code' => $currency, 'refund_transaction_id' => $refundId, 'journal_id' => $journal, 'reason' => $data['reason']], $correlation, $key, $hash);
            $this->events->publish(new DisputeRefundedEvent($id, $refundId, $actor, now()->toIso8601String(), $correlation));
            $this->events->publish(new AdminCaseEvent('DISPUTE_RESOLVED_WITH_REFUND', ['id' => $eventId, 'recipient_id' => $d->reporter_id, 'case_id' => $id, 'refund_transaction_id' => $refundId, 'amount_minor' => $amount, 'currency_code' => $currency, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => $correlation]));

            return $this->cases->detail($id);
        });
    }
}
