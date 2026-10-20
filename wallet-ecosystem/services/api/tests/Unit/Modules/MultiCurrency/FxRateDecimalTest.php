<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\MultiCurrency;

use App\Modules\MultiCurrency\Application\Services\FxRateAdminService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FxRateDecimalTest extends TestCase
{
    public static function valid(): array
    {
        return [['0.00000001', '0'], ['9999999999.99999999', '0.99999'], ['1', '0.01000']];
    }

    #[DataProvider('valid')]
    public function test_database_decimal_boundaries_preserved(string $rate, string $spread): void
    {
        FxRateAdminService::decimals($rate, $spread);
        $this->addToAssertionCount(1);
    }

    public static function invalid(): array
    {
        return [['0', '0'], ['-1', '0'], ['1e3', '0'], ['0.123456789', '0'], ['10000000000', '0'], ['1', '1'], ['1', '0.000001'], ['1', '-0.01'], ['NaN', '0']];
    }

    #[DataProvider('invalid')]
    public function test_unsafe_decimal_rejected(string $rate, string $spread): void
    {
        $this->expectException(\DomainException::class);
        FxRateAdminService::decimals($rate, $spread);
    }
}
