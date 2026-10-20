<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Permissions\PlatformPermissions;
use Tests\Support\UnitTestCase;

uses(UnitTestCase::class);

describe('PlatformPermissions', function () {
    it('declares every permission identifier used in configuration routes', function () {
        $routeFile = base_path('routes/modules/configuration.php');
        $contents = file_get_contents($routeFile);

        // Extract raw string permission identifiers of form 'word.word.word'
        preg_match_all("/'([a-z]+\.[a-z]+\.[a-z]+)'/", $contents, $rawStrings);

        // All raw permission strings must be absent — they must reference the constant
        expect($rawStrings[1])
            ->toBeEmpty('Found raw permission strings in configuration routes. Use PlatformPermissions constants instead.');
    });

    it('all() returns every declared constant value', function () {
        $reflection = new ReflectionClass(PlatformPermissions::class);
        $constants = array_values($reflection->getConstants());

        expect(PlatformPermissions::all())->toEqual($constants);
    });

    it('permission identifiers follow the {module}.{resource}.{action} convention', function () {
        foreach (PlatformPermissions::all() as $permission) {
            expect(preg_match('/^[a-z]+\.[a-z]+\.[a-z]+$/', $permission))
                ->toBe(1, "Permission '{$permission}' does not follow {module}.{resource}.{action} convention.");
        }
    });
});
