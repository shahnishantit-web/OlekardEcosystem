<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Application\Services;

use App\Modules\MultiCurrency\Domain\Contracts\CurrencyDefinitionGuard;
use App\Modules\MultiCurrency\Domain\ValueObjects\CurrencyDefinitionSnapshot;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** MultiCurrency-owned PostgreSQL coordination for currency definition readers and writers. */
final class CurrencyDefinitionCoordinator implements CurrencyDefinitionGuard
{
    public function lockAndRead(string $currencyCode, string $transactionConnection): ?CurrencyDefinitionSnapshot
    {
        $currencyCode = strtoupper($currencyCode);
        if (preg_match('/^[A-Z]{3}$/D', $currencyCode) !== 1) {
            throw new \InvalidArgumentException('Currency coordination requires a canonical currency code.');
        }

        $transaction = DB::connection($transactionConnection);
        if ($transaction->transactionLevel() < 1) {
            throw new LogicException('Currency definition coordination requires an active caller transaction.');
        }

        $platform = DB::connection('platform');
        $this->assertSharedPrimaryDatabase($transaction, $platform);

        // PostgreSQL transaction-scoped advisory locks coordinate independent named
        // connections when they target the same database. The database trigger uses
        // this exact key for raw status/precision writes as well.
        FxRateAdminService::lock('currency:'.$currencyCode, $transaction);

        $isolation = $transaction->selectOne('SHOW transaction_isolation');
        if (strtolower((string) ($isolation->transaction_isolation ?? '')) !== 'read committed') {
            throw new LogicException('Currency coordination requires READ COMMITTED so the post-lock snapshot cannot reuse stale transaction state.');
        }

        // Read using the guarded transaction itself. This keeps the snapshot and
        // advisory lock on one connection and sees the commit that released the lock.
        $currency = $transaction->table('multi_currency.currencies')
            ->where('code', $currencyCode)
            ->first(['code', 'decimal_places', 'is_active', 'updated_at']);
        if ($currency === null) {
            return null;
        }

        return new CurrencyDefinitionSnapshot(
            code: (string) $currency->code,
            decimalPlaces: (int) $currency->decimal_places,
            active: (bool) $currency->is_active,
            updatedAt: $currency->updated_at === null ? null : (string) $currency->updated_at,
        );
    }

    private function assertSharedPrimaryDatabase(Connection $transaction, Connection $platform): void
    {
        foreach ([$transaction, $platform] as $connection) {
            if ($connection->getDriverName() !== 'pgsql') {
                throw new LogicException('Currency definition coordination requires PostgreSQL.');
            }
        }

        $transactionConfig = $transaction->getConfig();
        $platformConfig = $platform->getConfig();
        foreach (['host', 'port', 'database'] as $key) {
            if ((string) ($transactionConfig[$key] ?? '') !== (string) ($platformConfig[$key] ?? '')) {
                throw new LogicException('Events and MultiCurrency must use the same PostgreSQL primary database for currency coordination.');
            }
        }

        $transactionIdentity = $this->serverIdentity($transaction);
        $platformIdentity = $this->serverIdentity($platform);
        if ($transactionIdentity !== $platformIdentity
            || $transactionIdentity['in_recovery']
            || $platformIdentity['in_recovery']) {
            throw new LogicException('Events and MultiCurrency connections do not resolve to the same PostgreSQL primary database.');
        }
    }

    /** @return array{database: string, server: ?string, port: int, in_recovery: bool} */
    private function serverIdentity(Connection $connection): array
    {
        $row = $connection->selectOne(
            'SELECT current_database() AS database, inet_server_addr()::text AS server, inet_server_port() AS port, pg_is_in_recovery() AS in_recovery',
        );

        return [
            'database' => (string) $row->database,
            'server' => $row->server === null ? null : (string) $row->server,
            'port' => (int) $row->port,
            'in_recovery' => filter_var($row->in_recovery, FILTER_VALIDATE_BOOL),
        ];
    }
}
