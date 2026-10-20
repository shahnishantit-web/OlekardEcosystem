<?php

declare(strict_types=1);

/**
 * Automated credential exposure scanner for E1000 Provider Adapter Foundation.
 *
 * Scans E1000 source files for credential/secret keywords that MUST NOT
 * appear as assigned values in production code paths: contracts, value objects,
 * interfaces, exceptions, application services, and the NullProviderAdapter.
 *
 * Files EXCLUDED from scanning:
 * - Test files (tests/)
 * - Migration files
 * - This file itself
 */

use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

/** E1000 source directories/files to scan (production code only). */
const E1000_SOURCE_DIRS = [
    'app/Modules/Platform/Domain/Enums',
    'app/Modules/Platform/Domain/Exceptions',
    'app/Modules/Platform/Domain/ValueObjects/Providers',
    'app/Modules/Platform/Domain/Contracts/Providers',
    'app/Modules/Platform/Infrastructure/Providers',
    'app/Modules/Platform/Application/Services/ExternalReferenceMappingService.php',
];

/**
 * Credential terms whose VALUES (not names) should not appear in production code.
 * Detecting a term as a PHP string literal value (e.g. = 'my_secret') is a violation.
 */
const CREDENTIAL_TERMS = [
    'api_key',
    'secret',
    'password',
    'bearer',
    'private_key',
    'client_secret',
    'access_token',
    'refresh_token',
];

/** @return string[] */
function collectE1000SourceFiles(): array
{
    $base  = base_path();
    $files = [];

    foreach (E1000_SOURCE_DIRS as $path) {
        $abs = $base . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if (is_file($abs) && str_ends_with($abs, '.php')) {
            $files[] = $abs;
            continue;
        }

        if (is_dir($abs)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
    }

    return $files;
}

/**
 * Determine whether a matching line is an acceptable reference to a credential term.
 * Lines that DEFINE or ENFORCE the blocklist rule are acceptable.
 * Lines that ASSIGN credential values are violations.
 */
function isAcceptableLine(string $line): bool
{
    $trimmed = trim($line);

    // Comment or docblock lines are acceptable — they document the boundary rule
    if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
        return true;
    }

    $acceptable = [
        'CREDENTIAL_BLOCKLIST',      // The constant name in code
        'credential boundary',
        'credentials MUST NOT',
        'assertMetadataClean',       // Method name enforcing the check
        'InvalidArgumentException',  // Exception being thrown for credential exposure
        "Blocked metadata key",      // Part of the exception message
    ];

    foreach ($acceptable as $pattern) {
        if (str_contains($line, $pattern)) {
            return true;
        }
    }

    return false;
}

test('no credential term appears as an assigned value in E1000 production source files', function () {
    $files    = collectE1000SourceFiles();
    $findings = [];

    expect($files)->not->toBeEmpty('Expected at least one E1000 source file to scan.');

    foreach ($files as $file) {
        $relative = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file);
        $lines    = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $lineNumber => $line) {
            // Only flag lines that look like string literal assignments
            // e.g. $var = 'api_key' or ['api_key' => 'my-actual-secret']
            if (! preg_match('/=\s*[\'"]/', $line) && ! preg_match('/\[\s*[\'"]/', $line)) {
                continue;
            }

            foreach (CREDENTIAL_TERMS as $term) {
                if (! str_contains(strtolower($line), $term)) {
                    continue;
                }

                if (isAcceptableLine($line)) {
                    continue;
                }

                // The matching value is a credential term appearing in a string literal assignment
                $findings[] = sprintf('[%s:%d] Contains "%s": %s', $relative, $lineNumber + 1, $term, trim($line));
            }
        }
    }

    expect($findings)->toBeEmpty(
        sprintf(
            "Credential exposure detected in E1000 source files:\n%s",
            implode("\n", $findings),
        )
    );
});

test('ProviderRequest rejects metadata with blocklisted key api_key', function () {
    expect(fn () => \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['api_key' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('ProviderRequest rejects metadata with blocklisted key secret', function () {
    expect(fn () => \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['secret' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('ProviderRequest rejects metadata with blocklisted key password', function () {
    expect(fn () => \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['password' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('ProviderRequest rejects metadata with blocklisted key token', function () {
    expect(fn () => \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['token' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('ProviderRequest rejects metadata with blocklisted key private_key', function () {
    expect(fn () => \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['private_key' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('ProviderRequest rejects metadata with blocklisted key credential', function () {
    expect(fn () => \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest::forNonFinancialOperation(
        'entity-1', 'kyc', 'verify_identity', 'corr-1', ['credential' => 'value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('NormalizedWebhookEvent metadata default is empty — no credential keys injected', function () {
    $event = \App\Modules\Platform\Domain\ValueObjects\Providers\NormalizedWebhookEvent::unresolved(
        idempotencyKey:      'idem-test',
        correlationId:       'corr-test',
        providerKey:         'test_provider',
        providerReference:   null,
        normalizedEventType: 'payment.success',
        status:              \App\Modules\Platform\Domain\Enums\ProviderStatus::SUCCESS,
        receivedAt:          new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        rawPayload:          '{"status":"ok"}',
    );

    foreach (CREDENTIAL_TERMS as $term) {
        expect(array_key_exists($term, $event->metadata))->toBeFalse(
            "Default metadata should not contain credential key '{$term}'"
        );
    }
});

test('ProviderResponse public properties do not expose credential-named fields', function () {
    $response = \App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse::success(
        providerRef: 'TXN-001',
        rawCode:     '200',
        correlationId: 'corr-001',
    );

    $properties = (new \ReflectionClass($response))->getProperties(\ReflectionProperty::IS_PUBLIC);
    $propNames  = array_map(fn ($p) => $p->getName(), $properties);

    foreach (CREDENTIAL_TERMS as $term) {
        expect($propNames)->not->toContain($term, "ProviderResponse must not expose '{$term}' as a public property");
    }
});

test('ExternalReferenceMapping domain value object has no credential fields', function () {
    $properties = (new \ReflectionClass(\App\Modules\Platform\Domain\ValueObjects\Providers\ExternalReferenceMapping::class))
        ->getProperties(\ReflectionProperty::IS_PUBLIC);

    $propNames = array_map(fn ($p) => $p->getName(), $properties);

    foreach (CREDENTIAL_TERMS as $term) {
        expect($propNames)->not->toContain($term, "ExternalReferenceMapping must not expose '{$term}'");
    }
});
