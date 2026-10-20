<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transfer;

use App\Modules\Transfer\Application\Services\LookupTokenService;
use App\Modules\Transfer\Application\Services\TransferReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\Support\UnitTestCase;

final class TransferFoundationTest extends UnitTestCase
{
    // ── TransferReferenceGenerator ────────────────────────────────────────────

    public function test_generated_reference_has_correct_prefix(): void
    {
        $db = Mockery::mock('alias:' . DB::class);
        $db->shouldReceive('connection')->andReturnSelf();
        $db->shouldReceive('table')->andReturnSelf();
        $db->shouldReceive('where')->andReturnSelf();
        $db->shouldReceive('exists')->andReturn(false);

        $generator = new TransferReferenceGenerator();
        $reference = $generator->generate();

        $this->assertStringStartsWith('TXF-', $reference);
    }

    public function test_generated_reference_code_is_ten_characters(): void
    {
        $db = Mockery::mock('alias:' . DB::class);
        $db->shouldReceive('connection')->andReturnSelf();
        $db->shouldReceive('table')->andReturnSelf();
        $db->shouldReceive('where')->andReturnSelf();
        $db->shouldReceive('exists')->andReturn(false);

        $generator = new TransferReferenceGenerator();
        $reference = $generator->generate();

        // Format: TXF-XXXXXXXXXX — prefix 4 chars + 10 code chars = 14 total
        $this->assertSame(14, strlen($reference));
        $code = substr($reference, 4);
        $this->assertSame(10, strlen($code));
    }

    public function test_generated_reference_contains_no_ambiguous_characters(): void
    {
        $db = Mockery::mock('alias:' . DB::class);
        $db->shouldReceive('connection')->andReturnSelf();
        $db->shouldReceive('table')->andReturnSelf();
        $db->shouldReceive('where')->andReturnSelf();
        $db->shouldReceive('exists')->andReturn(false);

        $generator = new TransferReferenceGenerator();

        for ($i = 0; $i < 50; $i++) {
            $reference = $generator->generate();
            $code      = substr($reference, 4);

            $this->assertDoesNotMatchRegularExpression('/[IO10]/', $code, "Ambiguous char found in: {$code}");
        }
    }

    public function test_generator_retries_on_collision_and_succeeds(): void
    {
        $db = Mockery::mock('alias:' . DB::class);
        $db->shouldReceive('connection')->andReturnSelf();
        $db->shouldReceive('table')->andReturnSelf();
        $db->shouldReceive('where')->andReturnSelf();
        // First two calls return true (collision), third returns false (unique)
        $db->shouldReceive('exists')->andReturn(true, true, false);

        $generator = new TransferReferenceGenerator();
        $reference = $generator->generate();

        $this->assertStringStartsWith('TXF-', $reference);
    }

    // ── LookupTokenService ────────────────────────────────────────────────────

    public function test_issue_returns_uuid_string(): void
    {
        $redisConn = Mockery::mock();
        $redisConn->shouldReceive('setex')->once()->andReturn('OK');

        $redis = Mockery::mock('alias:' . Redis::class);
        $redis->shouldReceive('connection')->with('default')->andReturn($redisConn);

        $service = new LookupTokenService(ttlSeconds: 300);
        $token   = $service->issue(
            recipientCustomerId:       'cust-123',
            recipientWalletId:         'wallet-456',
            pocketLedgerRef:           'pocket-ref',
            currencyCode:              'BWP',
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $token,
            'Token should be a UUID v4.'
        );
    }

    public function test_consume_returns_payload_on_valid_token(): void
    {
        $payload = json_encode([
            'recipient_customer_id'       => 'cust-123',
            'recipient_wallet_id'         => 'wallet-456',
            'recipient_pocket_ledger_ref' => 'pocket-ref',
            'currency_code'               => 'BWP',
        ]);

        $redisConn = Mockery::mock();
        $redisConn->shouldReceive('command')->with('GETDEL', Mockery::any())->andReturn($payload);

        $redis = Mockery::mock('alias:' . Redis::class);
        $redis->shouldReceive('connection')->with('default')->andReturn($redisConn);

        $service = new LookupTokenService(ttlSeconds: 300);
        $result  = $service->consume('some-valid-token');

        $this->assertIsArray($result);
        $this->assertSame('cust-123', $result['recipient_customer_id']);
        $this->assertSame('BWP', $result['currency_code']);
    }

    public function test_consume_returns_null_on_missing_or_expired_token(): void
    {
        $redisConn = Mockery::mock();
        $redisConn->shouldReceive('command')->with('GETDEL', Mockery::any())->andReturn(null);

        $redis = Mockery::mock('alias:' . Redis::class);
        $redis->shouldReceive('connection')->with('default')->andReturn($redisConn);

        $service = new LookupTokenService(ttlSeconds: 300);
        $result  = $service->consume('expired-or-missing-token');

        $this->assertNull($result);
    }

    public function test_consume_returns_null_on_second_call_for_same_token(): void
    {
        $payload = json_encode([
            'recipient_customer_id'       => 'cust-123',
            'recipient_wallet_id'         => 'wallet-456',
            'recipient_pocket_ledger_ref' => 'pocket-ref',
            'currency_code'               => 'BWP',
        ]);

        $redisConn = Mockery::mock();
        $redisConn->shouldReceive('command')
            ->with('GETDEL', Mockery::any())
            ->andReturn($payload, null); // second call returns null (already deleted)

        $redis = Mockery::mock('alias:' . Redis::class);
        $redis->shouldReceive('connection')->with('default')->andReturn($redisConn);

        $service = new LookupTokenService(ttlSeconds: 300);

        $first  = $service->consume('some-token');
        $second = $service->consume('some-token');

        $this->assertIsArray($first);
        $this->assertNull($second);
    }
}
