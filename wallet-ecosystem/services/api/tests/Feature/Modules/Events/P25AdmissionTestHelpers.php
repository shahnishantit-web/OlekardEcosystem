<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Events;

use App\Modules\Events\Application\AdmissionCredentialVault;
use App\Modules\Events\Application\EventPaymentConfirmationConsumer;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

trait P25AdmissionTestHelpers
{
    /** @return array{event_id:string,gate_id:string,order_id:string,ticket_id:string,credential:string} */
    private function issueAdmissionTicket(int $quantity = 1): array
    {
        $prepared = $this->prepareOrder($quantity, 'admission-'.Uuid::uuid7());
        $intentId = (string) Uuid::uuid7();
        $this->linkPaymentIntent($prepared['order']['order_id'], $intentId, (int) $prepared['order']['total_minor'], $prepared['event']['currency_code']);
        $this->bindConfirmation($prepared, $intentId, static function (): void {}, readCalls: 1, ackCalls: 1);
        app(EventPaymentConfirmationConsumer::class)->handle($this->confirmationPayload($prepared, $intentId));
        $db = DB::connection('community_services');
        $ticket = $db->table('community_services.event_tickets')->where('order_id', $prepared['order']['order_id'])->first();
        $this->assertNotNull($ticket, 'Test-only P08 evidence should issue the admission fixture.');
        $gateId = (string) Uuid::uuid7();
        $db->table('community_services.event_gates')->insert([
            'id' => $gateId, 'organisation_id' => $this->organisation, 'event_id' => $prepared['event']['id'],
            'name' => 'Fixture gate', 'status' => 'active', 'created_by_actor_id' => $this->actor,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['event_id' => $prepared['event']['id'], 'gate_id' => $gateId,
            'order_id' => $prepared['order']['order_id'], 'ticket_id' => (string) $ticket->id,
            'credential' => app(AdmissionCredentialVault::class)->recoverForDisplay((string) $ticket->credential_encrypted)];
    }

    private function insertAdmissionPaymentFixture(string $intentId, string $attemptId): void
    {
        $payment = DB::connection('payment');
        $provider = $payment->table('payment.payment_providers')->orderBy('code')->first();
        $this->assertNotNull($provider, 'Payment provider fixture is required by the attempt foreign key.');
        $accountId = (string) Uuid::uuid7();
        $payment->table('payment.payment_provider_accounts')->insert([
            'id' => $accountId, 'provider_id' => $provider->id, 'merchant_account_reference' => 'p25-test-'.$accountId,
            'supported_currencies' => json_encode(['BWP'], JSON_THROW_ON_ERROR), 'supported_payment_methods' => json_encode(['test'], JSON_THROW_ON_ERROR),
            'enabled_organisations' => json_encode([], JSON_THROW_ON_ERROR), 'enabled_modules' => json_encode([], JSON_THROW_ON_ERROR),
            'country_restrictions' => json_encode([], JSON_THROW_ON_ERROR), 'capability_flags' => json_encode([], JSON_THROW_ON_ERROR),
            'fee_rule_ids' => json_encode([], JSON_THROW_ON_ERROR), 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment->table('payment.payment_intents')->insert([
            'id' => $intentId, 'reference' => 'P25'.strtoupper(substr(str_replace('-', '', $intentId), 0, 20)),
            'purpose_type' => 'paid_event_ticket', 'purpose_reference' => (string) Uuid::uuid7(),
            'organisation_id' => $this->organisation, 'payer_actor_id' => $this->actor, 'currency_code' => 'BWP',
            'amount_minor' => 1000, 'status' => 'succeeded', 'idempotency_key' => 'p25-admit-'.$intentId,
            'correlation_id' => (string) Uuid::uuid7(), 'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment->table('payment.payment_attempts')->insert([
            'id' => $attemptId, 'payment_intent_id' => $intentId, 'provider_code' => $provider->code,
            'provider_account_id' => $accountId, 'status' => 'succeeded', 'idempotency_key' => 'p25-admit-attempt-'.$attemptId,
            'fee_minor' => 0, 'initiated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
