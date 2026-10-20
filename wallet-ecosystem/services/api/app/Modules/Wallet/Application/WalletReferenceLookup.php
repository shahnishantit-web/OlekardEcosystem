<?php
declare(strict_types=1);
namespace App\Modules\Wallet\Application;
use App\Modules\Wallet\Domain\Contracts\WalletReferenceLookupContract;
use App\Modules\Wallet\Infrastructure\Models\WalletModel;
final class WalletReferenceLookup implements WalletReferenceLookupContract
{
    public function walletIdForReference(string $reference): ?string
    {
        return WalletModel::where('wallet_reference', $reference)->value('id');
    }
}
