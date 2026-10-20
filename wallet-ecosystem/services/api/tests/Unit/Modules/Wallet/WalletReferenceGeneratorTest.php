<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Application\Services\WalletReferenceGenerator;
use App\Modules\Wallet\Domain\Exceptions\WalletReferenceCollisionException;
use Tests\TestCase;

final class WalletReferenceGeneratorTest extends TestCase
{
    /** @test */
    public function generated_reference_matches_default_pattern(): void
    {
        $alwaysUnique = fn (string $_) => true;
        $gen          = new WalletReferenceGenerator($alwaysUnique);

        $ref = $gen->generate();

        // Format: WLT-YYYYMMDD-XXXXXXXX (8 uppercase alphanum)
        $this->assertMatchesRegularExpression('/^WLT-\d{8}-[A-Z0-9]{8}$/', $ref);
    }

    /** @test */
    public function generated_references_are_unique_across_1000_calls(): void
    {
        $alwaysUnique = fn (string $_) => true;
        $gen          = new WalletReferenceGenerator($alwaysUnique);
        $refs         = [];

        for ($i = 0; $i < 1000; $i++) {
            $refs[] = $gen->generate();
        }

        $this->assertSame(1000, count(array_unique($refs)));
    }

    /** @test */
    public function retries_on_collision_and_succeeds_after_one_collision(): void
    {
        $callCount = 0;
        $checker   = function (string $_) use (&$callCount): bool {
            $callCount++;
            // First call = collision (not unique), second = unique
            return $callCount > 1;
        };

        $gen = new WalletReferenceGenerator($checker);
        $ref = $gen->generate();

        $this->assertMatchesRegularExpression('/^WLT-\d{8}-[A-Z0-9]{8}$/', $ref);
        $this->assertSame(2, $callCount, 'Expected exactly 2 uniqueness checks (1 collision + 1 success)');
    }

    /** @test */
    public function throws_after_max_attempts_with_persistent_collisions(): void
    {
        $alwaysCollides = fn (string $_) => false;
        $gen            = new WalletReferenceGenerator($alwaysCollides);

        $this->expectException(\RuntimeException::class);
        $gen->generate();
    }

    /** @test */
    public function null_reference_format_config_uses_default_pattern(): void
    {
        config(['wallet.reference_format' => null]);

        $alwaysUnique = fn (string $_) => true;
        $gen          = new WalletReferenceGenerator($alwaysUnique);

        $ref = $gen->generate();
        $this->assertStringStartsWith('WLT-', $ref);
    }
}
