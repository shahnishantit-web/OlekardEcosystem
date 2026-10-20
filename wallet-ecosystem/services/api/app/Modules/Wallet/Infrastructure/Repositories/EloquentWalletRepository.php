<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Repositories;

use App\Modules\Wallet\Domain\Exceptions\WalletReferenceCollisionException;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
use Illuminate\Database\QueryException;

final class EloquentWalletRepository
{
    public function findById(string $walletId): ?WalletModel
    {
        return WalletModel::on('wallet')->find($walletId);
    }

    public function findByOwnerId(string $ownerId): ?WalletModel
    {
        return WalletModel::on('wallet')
            ->where('owner_id', $ownerId)
            ->latest('created_at')
            ->first();
    }

    public function findActiveByOwnerId(string $ownerId): ?WalletModel
    {
        return WalletModel::on('wallet')
            ->where('owner_id', $ownerId)
            ->where('status', '!=', 'closed')
            ->first();
    }

    /**
     * @throws WalletReferenceCollisionException when a unique constraint violation occurs on wallet_reference
     */
    public function save(WalletModel $wallet): void
    {
        try {
            $wallet->save();
        } catch (QueryException $e) {
            // Detect unique constraint violation specifically on wallet_reference
            if ($this->isWalletReferenceUniqueViolation($e)) {
                throw new WalletReferenceCollisionException(
                    'Wallet reference already exists: ' . ($wallet->wallet_reference ?? 'unknown')
                );
            }

            throw $e;
        }
    }

    private function isWalletReferenceUniqueViolation(QueryException $e): bool
    {
        // PostgreSQL unique violation code: 23505
        $code = $e->getCode();
        $msg  = $e->getMessage();

        return $code === '23505' && str_contains($msg, 'wallet_reference');
    }
}
