<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Modules\Payment\Domain\ValueObjects\CsvFormulaSanitiser;
use PHPUnit\Framework\TestCase;

final class CsvFormulaSanitiserTest extends TestCase
{
    public function test_equals_prefix_is_sanitised(): void
    {
        $this->assertSame("'=SUM(A1:A100)", CsvFormulaSanitiser::sanitise('=SUM(A1:A100)'));
    }

    public function test_plus_prefix_is_sanitised(): void
    {
        $this->assertSame("'+ injected", CsvFormulaSanitiser::sanitise('+ injected'));
    }

    public function test_minus_prefix_is_sanitised(): void
    {
        $this->assertSame("'- injected", CsvFormulaSanitiser::sanitise('- injected'));
    }

    public function test_at_sign_prefix_is_sanitised(): void
    {
        $this->assertSame("'@SUM(1+2)", CsvFormulaSanitiser::sanitise('@SUM(1+2)'));
    }

    public function test_tab_prefix_is_sanitised(): void
    {
        $this->assertSame("'\tinjected", CsvFormulaSanitiser::sanitise("\tinjected"));
    }

    public function test_carriage_return_prefix_is_sanitised(): void
    {
        $this->assertSame("'\rinjected", CsvFormulaSanitiser::sanitise("\rinjected"));
    }

    public function test_safe_value_is_unchanged(): void
    {
        $this->assertSame('Normal value', CsvFormulaSanitiser::sanitise('Normal value'));
    }

    public function test_empty_string_is_unchanged(): void
    {
        $this->assertSame('', CsvFormulaSanitiser::sanitise(''));
    }

    public function test_numeric_string_is_unchanged(): void
    {
        $this->assertSame('12345', CsvFormulaSanitiser::sanitise('12345'));
    }

    public function test_url_is_unchanged(): void
    {
        $this->assertSame('https://example.com/path', CsvFormulaSanitiser::sanitise('https://example.com/path'));
    }
}
