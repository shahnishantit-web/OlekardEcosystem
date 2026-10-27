<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentInstrumentType: string
{
    case WalletTransfer = 'wallet_transfer';
    case BankTransfer   = 'bank_transfer';
    case CardPayment    = 'card_payment';
    case QrPayment      = 'qr_payment';
    case MobileMoney    = 'mobile_money';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
