<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class CheckScopeCommand extends Command
{
    protected $signature = 'platform:check-scope {--fix : Dry run, suggests fixes}';

    protected $description = 'Enforce module boundary rules: no cross-domain imports, no cross-schema JOINs';

    public function handle(): int
    {
        $violations = [];
        $modulesPath = app_path('Modules');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modulesPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath  = $file->getPathname();
            $contents  = file_get_contents($filePath);
            $namespace = $this->extractNamespace($filePath);

            // Check cross-domain imports (allow Platform as shared kernel)
            $violations = array_merge(
                $violations,
                $this->checkCrossDomainImports($filePath, $namespace, $contents),
            );

            // Check cross-schema SQL JOINs in repositories
            $violations = array_merge(
                $violations,
                $this->checkCrossSchemaJoins($filePath, $contents),
            );
        }

        if (count($violations) === 0) {
            $this->info('Scope: OK');
            return self::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->error($violation);
        }

        $this->newLine();
        $this->error(sprintf('Module boundary check FAILED. %d violation(s) found.', count($violations)));

        return self::FAILURE;
    }

    private function extractNamespace(string $filePath): string
    {
        $relativePath = str_replace(
            [app_path('Modules') . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, '.php'],
            ['App\\Modules\\', '\\', ''],
            $filePath,
        );

        // Get parent class namespace (module root)
        $parts = explode('\\', $relativePath);

        return implode('\\', array_slice($parts, 0, 3)); // App\Modules\{Module}
    }

    /** @return string[] */
    private function checkCrossDomainImports(string $filePath, string $ownerModule, string $contents): array
    {
        $violations = [];
        $lines = explode("\n", $contents);

        foreach ($lines as $lineNo => $line) {
            if (! str_starts_with(trim($line), 'use App\\Modules\\')) {
                continue;
            }

            // Extract the imported module name
            preg_match('/use App\\\\Modules\\\\(\w+)/', $line, $matches);
            $importedModule = $matches[1] ?? null;

            if ($importedModule === null || $importedModule === 'Platform') {
                continue; // Platform is the shared kernel — allowed
            }

            // Detect if this file's module is different from the imported module
            $ownerParts = explode('\\', $ownerModule);
            $ownerModuleName = $ownerParts[2] ?? null;

            if ($ownerModuleName !== null && $ownerModuleName !== $importedModule) {
                $violations[] = sprintf(
                    'ERROR: [%s:%d] Cross-domain import detected: %s imported in %s module.',
                    $filePath,
                    $lineNo + 1,
                    trim($line),
                    $ownerModuleName,
                );
            }
        }

        return $violations;
    }

    /** @return string[] */
    private function checkCrossSchemaJoins(string $filePath, string $contents): array
    {
        $violations = [];

        // Look for SQL JOINs that cross schemas: JOIN {schema}.{table}
        if (preg_match_all('/JOIN\s+\w+\.\w+/i', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$match, $offset]) {
                $lineNo = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $violations[] = sprintf(
                    'ERROR: [%s:%d] Cross-schema SQL JOIN detected: "%s". Use module contracts instead.',
                    $filePath,
                    $lineNo,
                    $match,
                );
            }
        }

        return $violations;
    }
}
