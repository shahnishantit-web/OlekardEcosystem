<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Application\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Transfer\Domain\Exceptions\RecipientNotFoundException;
use App\Modules\Transfer\Domain\Exceptions\RecipientWalletInactiveException;
use App\Modules\Transfer\Domain\Exceptions\SelfTransferException;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;

final class RecipientLookupService
{
    public function __construct(
        private readonly IdentityModuleContract $identity,
        private readonly WalletModuleContract   $wallet,
        private readonly LookupTokenService     $lookupTokens,
        private readonly TransferMetricsService $metrics,
    ) {}

    /**
     * @param string      $senderCustomerId  Authenticated sender (to enforce self-transfer check)
     * @param string|null $phoneNumber       E.164 phone number (XOR with walletId)
     * @param string|null $walletId          Recipient wallet UUID (XOR with phoneNumber)
     * @param string      $currencyCode      Required currency code for the transfer
     *
     * @return array{
     *   recipient_customer_id:      string,
     *   recipient_wallet_id:        string,
     *   display_name:               string,
     *   masked_mobile:              string,
     *   lookup_token:               string,
     * }
     *
     * @throws RecipientNotFoundException
     * @throws RecipientWalletInactiveException
     * @throws SelfTransferException
     */
    public function lookup(
        string  $senderCustomerId,
        ?string $phoneNumber,
        ?string $walletId,
        string  $currencyCode,
    ): array {
        if ($phoneNumber !== null) {
            $recipientCustomerId = $this->identity->findCustomerIdByMobile($phoneNumber);

            if ($recipientCustomerId === null) {
                $this->metrics->recipientLookup(false);
                throw new RecipientNotFoundException($phoneNumber);
            }

            $recipientWalletId = $this->resolveWalletForCustomer($recipientCustomerId, $currencyCode);
        } else {
            if (! \Illuminate\Support\Str::isUuid($walletId ?? '')) {
                $walletId = app(\App\Modules\Wallet\Domain\Contracts\WalletReferenceLookupContract::class)
                    ->walletIdForReference($walletId ?? '');
                if ($walletId === null) throw new RecipientNotFoundException('wallet');
            }
            // Lookup by wallet ID — validate that the wallet belongs to a customer
            $recipientWalletId   = $walletId;
            $recipientCustomerId = $this->wallet->ownerActorId($walletId);

            if ($recipientCustomerId === null) {
                throw new RecipientNotFoundException($walletId);
            }
        }

        if ($recipientCustomerId === $senderCustomerId) {
            throw new SelfTransferException();
        }

        if (! $this->wallet->isActive($recipientWalletId)) {
            throw new RecipientWalletInactiveException($recipientWalletId);
        }

        $pocketLedgerRef = $this->wallet->getPocketLedgerAccountRef($recipientWalletId, $currencyCode);

        if ($pocketLedgerRef === null) {
            throw new RecipientWalletInactiveException($recipientWalletId);
        }

        $lookupToken = $this->lookupTokens->issue(
            recipientCustomerId:       $recipientCustomerId,
            recipientWalletId:         $recipientWalletId,
            pocketLedgerRef:           $pocketLedgerRef,
            currencyCode:              $currencyCode,
        );

        $this->metrics->recipientLookup(true);

        return [
            'recipient_customer_id' => $recipientCustomerId,
            'recipient_wallet_id'   => $recipientWalletId,
            'display_name'          => '',
            'masked_mobile'         => $phoneNumber !== null ? $this->maskMobile($phoneNumber) : '',
            'lookup_token'          => $lookupToken,
        ];
    }

    private function resolveWalletForCustomer(string $customerId, string $currencyCode): string
    {
        // In the monolith phase, find the customer's primary active wallet.
        // WalletModuleContract doesn't expose a findPrimaryWalletByOwner method yet,
        // so we query directly. This will be replaced by a contract call when extracted.
        $walletId = $this->wallet->findPrimaryWalletId($customerId);

        if ($walletId === null) {
            throw new RecipientNotFoundException($customerId);
        }

        return $walletId;
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 5) {
            return str_repeat('*', strlen($mobile));
        }

        return substr($mobile, 0, 3) . str_repeat('*', strlen($mobile) - 5) . substr($mobile, -2);
    }
}
