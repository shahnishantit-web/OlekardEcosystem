<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->customer = app(UserRepositoryInterface::class)->create([
        'mobile_number' => '+26771000991',
        'status' => 'active',
        'actor_type' => 'CUSTOMER',
    ]);
    app(CredentialRepositoryInterface::class)->create([
        'user_id' => $this->customer->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('123456'),
        'status' => 'active',
    ]);
    app(DeviceRepositoryInterface::class)->create([
        'user_id' => $this->customer->id,
        'device_fingerprint' => hash('sha256', 'mobile-customer-+26771000991'),
        'status' => 'trusted',
        'platform' => 'mobile',
    ]);
    $this->challenge = $this->postJson('/v1/customer/auth/login', [
        'phone' => '+26771000991', 'method' => 'pin',
    ])->assertOk()->json('challenge_token');
});

it('verifies the entered customer PIN and consumes the challenge', function (): void {
    $this->postJson('/v1/customer/auth/login/verify', [
        'challenge_token' => $this->challenge, 'pin' => '123456',
    ])->assertOk()->assertJsonPath('customer.id', $this->customer->id)
        ->assertJsonStructure(['access_token', 'refresh_token', 'customer']);
    $this->postJson('/v1/customer/auth/login/verify', [
        'challenge_token' => $this->challenge, 'pin' => '123456',
    ])->assertUnauthorized()->assertJsonPath('error.code', 'IDENTITY_003');
});

it('audits a wrong PIN in its own transaction and returns 401 instead of 500', function (): void {
    $baseline = DB::connection('platform')->transactionLevel();
    $publisher = new class($baseline) implements EventPublisherInterface {
        public bool $published = false;
        public function __construct(private readonly int $baseline) {}
        public function publish(IntegrationEventInterface $event): void
        {
            expect(DB::connection('platform')->transactionLevel())->toBeGreaterThan($this->baseline);
            (new OutboxEventPublisher)->publish($event);
            $this->published = true;
        }
        public function publishAll(array $events): void
        {
            foreach ($events as $event) {
                $this->publish($event);
            }
        }
    };
    app()->instance(EventPublisherInterface::class, $publisher);
    $this->postJson('/v1/customer/auth/login/verify', [
        'challenge_token' => $this->challenge, 'pin' => '654321',
    ])->assertUnauthorized()->assertJsonPath('error.code', 'IDENTITY_002');
    expect($publisher->published)->toBeTrue();
    expect($this->customer->fresh()->failed_attempt_count)->toBe(1);
    expect(DB::connection('platform')->table('platform.outbox_events')
        ->where('aggregate_id', $this->customer->id)
        ->where('event_name', 'Identity.Session.LoginFailed')->exists())->toBeTrue();
});

it('rejects a client bcrypt hash without counting it as a PIN attempt', function (): void {
    $this->postJson('/v1/customer/auth/login/verify', [
        'challenge_token' => $this->challenge, 'pin_hash' => Hash::make('123456'),
    ])->assertUnprocessable();
    expect($this->customer->fresh()->failed_attempt_count)->toBe(0);
});

it('rejects expired customer challenges', function (): void {
    $this->postJson('/v1/customer/auth/login/verify', [
        'challenge_token' => 'expired', 'pin' => '123456',
    ])->assertUnauthorized()->assertJsonPath('error.code', 'IDENTITY_003');
});
