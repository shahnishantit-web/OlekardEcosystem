<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PhpSourceEncodingTest extends TestCase
{
    public function test_runtime_php_files_start_at_the_opening_tag_without_a_bom(): void
    {
        $root = dirname(__DIR__, 2);
        $checked = 0;
        foreach (['config', 'bootstrap', 'app', 'routes'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root.'/'.$directory, \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') continue;
                $stream = fopen($file->getPathname(), 'rb');
                $prefix = fread($stream, 5);
                fclose($stream);
                self::assertSame('<?php', $prefix, $file->getPathname().' must start with <?php without a byte-order mark.');
                $checked++;
            }
        }
        self::assertGreaterThan(0, $checked);
    }
}
