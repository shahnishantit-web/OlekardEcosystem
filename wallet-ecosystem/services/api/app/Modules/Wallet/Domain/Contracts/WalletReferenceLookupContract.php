<?php
declare(strict_types=1);
namespace App\Modules\Wallet\Domain\Contracts;
interface WalletReferenceLookupContract
{
    public function walletIdForReference(string $reference): ?string;
}
