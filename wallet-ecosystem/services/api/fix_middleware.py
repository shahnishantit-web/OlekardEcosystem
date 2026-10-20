import os
import re

base = r'C:\Projects\OleKardEcosystem\wallet-ecosystem\services\api'

files = [
    'app/Modules/Identity/Tests/Feature/Device/AcknowledgeNewDeviceTest.php',
    'app/Modules/Identity/Tests/Feature/Device/BlockDeviceTest.php',
    'app/Modules/Identity/Tests/Feature/Device/DeviceChangeDetectionApiTest.php',
    'app/Modules/Identity/Tests/Feature/Device/GenerateDeviceFingerprintTest.php',
    'app/Modules/Identity/Tests/Feature/Device/ListDevicesTest.php',
    'app/Modules/Identity/Tests/Feature/Device/RegisterDeviceTest.php',
    'app/Modules/Identity/Tests/Feature/Device/RemoveDeviceTest.php',
    'app/Modules/Identity/Tests/Feature/Device/SuspiciousDetectionIntegrationTest.php',
    'app/Modules/Identity/Tests/Feature/Device/TrustDeviceTest.php',
    'app/Modules/Identity/Tests/Performance/DeviceListPerformanceTest.php',
    'app/Modules/Identity/Tests/Performance/DeviceRegistrationPerformanceTest.php',
    'app/Modules/Identity/Tests/Security/Device/SuspiciousDetectionOpacityTest.php',
    'app/Modules/Identity/Tests/Unit/DeviceChangeDetectionTest.php',
    'app/Modules/Identity/Tests/Unit/SuspiciousDeviceDetectionTest.php',
    'tests/Feature/Modules/Identity/DeviceManagementTest.php',
    'tests/Feature/Modules/Identity/AdminDeviceTest.php',
    'tests/Security/Modules/Identity/DeviceIdarTest.php',
]

# Pattern matches the 3-line resolving block with any indentation
# $this->app->resolving(Router::class, [static] function (Router $r): void {
#     $r->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
# });
pattern = re.compile(
    r'([ \t]*)\$this->app->resolving\(Router::class,\s+(?:static\s+)?function\s*\(Router\s+\$r\):\s*void\s*\{[ \t]*\n'
    r'[ \t]*\$r->aliasMiddleware\(\'identity\.auth\',\s*FakeIdentityAuthMiddleware::class\);[ \t]*\n'
    r'[ \t]*\}\);[ \t]*\n'
)

fixed = 0
for rel_path in files:
    path = os.path.join(base, rel_path.replace('/', os.sep))
    if not os.path.exists(path):
        print(f'MISSING: {rel_path}')
        continue

    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    def make_replacement(m):
        indent = m.group(1)
        return f"{indent}$this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);\n"

    new_content = pattern.sub(make_replacement, content)

    if new_content == content:
        print(f'NO MATCH: {rel_path}')
        continue

    # Remove 'use Illuminate\Routing\Router;' if Router no longer referenced
    if 'Router' not in new_content.replace('use Illuminate\\Routing\\Router;', ''):
        new_content = new_content.replace("use Illuminate\\Routing\\Router;\n", '')

    with open(path, 'w', encoding='utf-8') as f:
        f.write(new_content)
    fixed += 1
    print(f'Fixed: {rel_path}')

print(f'\nTotal fixed: {fixed}')
