<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;
use Tests\Support\AssertOutboxEvent;

/**
 * T074 — US1b: Explicit ledger account registration endpoint.
 * POST /v1/ledger/accounts — idempotent, conflict detection, event emission.
 */
final class RegisterAccountTest extends LedgerTestCase
{
    use AssertOutboxEvent;

    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission('ledger.account.register');
    }

    private function registrationPayload(?string $ref = null): array
    {
        return [
            'ledger_account_reference' => $ref ?? 'reg-acct-' . substr((string) Uuid::uuid7(), 0, 8),
            'account_type'             => 'liability',
            'currency_code'            => 'KES',
            'owner_reference'          => (string) Uuid::uuid7(),
            'owner_type'               => 'customer_pocket',
        ];
    }

    // Scenario (a): new registration → 201, account fields returned.
    public function test_new_registration_returns_201_with_account_fields(): void
    {
        $payload = $this->registrationPayload();

        $response = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/accounts', $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('data.ledger_account_reference', $payload['ledger_account_reference'])
                 ->assertJsonPath('data.account_type', 'liability')
                 ->assertJsonPath('data.currency_code', 'KES')
                 ->assertJsonPath('data.status', 'active');

        $this->assertNotNull($response->json('data.id'));
    }

    // Scenario (b): same Idempotency-Key replayed → 200 with same account record (idempotent).
    public function test_idempotent_replay_returns_200_with_same_account(): void
    {
        $payload        = $this->registrationPayload();
        $idempotencyKey = (string) Uuid::uuid7();

        $first = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/v1/ledger/accounts', $payload)
            ->assertStatus(201);

        $secondResponse = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson('/v1/ledger/accounts', $payload);

        $secondResponse->assertStatus(200)
                       ->assertJsonPath('data.id', $first->json('data.id'));

        // Only one record in DB
        $count = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->where('ledger_account_reference', $payload['ledger_account_reference'])
            ->count();
        $this->assertSame(1, $count);
    }

    // Scenario (c): conflicting account_type on existing reference → 409 ACCOUNT_CONFLICT.
    public function test_conflicting_account_type_returns_409(): void
    {
        $ref     = 'conflict-ref-' . substr((string) Uuid::uuid7(), 0, 8);
        $payload = $this->registrationPayload($ref);

        // Register as liability
        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/accounts', $payload)
            ->assertStatus(201);

        // Attempt to register same ref as asset (conflict)
        $conflicting             = $payload;
        $conflicting['account_type'] = 'asset';

        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/accounts', $conflicting)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ACCOUNT_CONFLICT');
    }

    // Scenario (d): without ledger.account.register permission → 403.
    public function test_missing_register_permission_returns_403(): void
    {
        $noPermission = $this->makeAdminWithPermission('ledger.read');
        $payload      = $this->registrationPayload();

        $this->withActorToken($noPermission)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/accounts', $payload)
            ->assertStatus(403);
    }

    // Scenario (e): successful registration → Ledger.Account.Registered outbox event emitted.
    public function test_registration_emits_ledger_account_registered_outbox_event(): void
    {
        $payload = $this->registrationPayload();

        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/accounts', $payload)
            ->assertStatus(201);

        $this->assertOutboxEventPublished('Ledger.Account.Registered', [
            'ledger_account_reference' => $payload['ledger_account_reference'],
        ]);
    }

    // Scenario (f): missing Idempotency-Key → 422.
    public function test_missing_idempotency_key_returns_422(): void
    {
        $this->withActorToken($this->admin)
            ->postJson('/v1/ledger/accounts', $this->registrationPayload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MISSING_IDEMPOTENCY_KEY');
    }
}
