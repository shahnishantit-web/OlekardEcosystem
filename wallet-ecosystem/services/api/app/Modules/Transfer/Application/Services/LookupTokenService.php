<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use Illuminate\Support\Facades\Redis;
use Ramsey\Uuid\Uuid;

final class LookupTokenService
{
    private const KEY_PREFIX = 'transfer_lookup:';
    private const DEFAULT_TTL = 300;

    public function __construct(private readonly int $ttlSeconds = self::DEFAULT_TTL) {}

    /**
     * Issue a single-use lookup token.
     * Stores recipient context as JSON in Redis with TTL.
     * Returns the token UUID (used by client in transfer initiation).
     */
    public function issue(
        string $recipientCustomerId,
        string $recipientWalletId,
        string $pocketLedgerRef,
        string $currencyCode,
    ): string {
        $token = (string) Uuid::uuid4();

        $payload = json_encode([
            'recipient_customer_id'     => $recipientCustomerId,
            'recipient_wallet_id'       => $recipientWalletId,
            'recipient_pocket_ledger_ref' => $pocketLedgerRef,
            'currency_code'             => $currencyCode,
        ], JSON_THROW_ON_ERROR);

        Redis::connection('default')
            ->setex(self::KEY_PREFIX . $token, $this->ttlSeconds, $payload);

        return $token;
    }

    /**
     * Consume a lookup token atomically (GETDEL).
     * Returns the decoded payload array, or null if missing/expired.
     * Single-use: the key is deleted on retrieval.
     */
    public function consume(string $token): ?array
    {
        $raw = Redis::connection('default')->command('GETDEL', [self::KEY_PREFIX . $token]);

        if ($raw === null || $raw === false) {
            return null;
        }

        return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
