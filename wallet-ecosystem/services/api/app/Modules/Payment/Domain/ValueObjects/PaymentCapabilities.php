<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Contracts\InitiatesPayment;
use App\Modules\Payment\Domain\Contracts\QueriesPayment;
use App\Modules\Payment\Domain\Contracts\RefundsPayment;
use App\Modules\Payment\Domain\Contracts\InitiatesPayout;
use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;

final class PaymentCapabilities
{
    public static function initiate(object $adapter): InitiatesPayment
    {
        return $adapter instanceof InitiatesPayment ? $adapter : throw new CapabilityUnsupportedException($adapter::class, 'initiate');
    }

    public static function query(object $adapter): QueriesPayment
    {
        return $adapter instanceof QueriesPayment ? $adapter : throw new CapabilityUnsupportedException($adapter::class, 'query');
    }

    public static function refund(object $adapter): RefundsPayment
    {
        return $adapter instanceof RefundsPayment ? $adapter : throw new CapabilityUnsupportedException($adapter::class, 'refund');
    }

    public static function payout(object $adapter): InitiatesPayout
    {
        return $adapter instanceof InitiatesPayout ? $adapter : throw new CapabilityUnsupportedException($adapter::class, 'payout');
    }
}
