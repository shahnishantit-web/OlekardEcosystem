[CmdletBinding()]
param(
    [switch]$FocusedCustomerLogin,
    [switch]$Headed,
    [switch]$NativeCustomerLogin,
    [switch]$NativeEventJourney,
    [switch]$NativeOnly,
    [switch]$EventsFoundationRegression,
    [string]$EventsTestFilter
)

$ErrorActionPreference = 'Stop'
$hadNoColorSetting = Test-Path Env:NO_COLOR
$savedNoColorSetting = $env:NO_COLOR
if ($hadNoColorSetting) {
    # npm/Playwright may set FORCE_COLOR for its child process. Remove only
    # the conflicting per-run NO_COLOR value, then restore it in finally.
    Remove-Item Env:NO_COLOR -ErrorAction SilentlyContinue
}
$runSuffix = [Guid]::NewGuid().ToString('N').Substring(0, 8)
$runDate = Get-Date -Format 'yyyyMMdd'
$databaseName = "wallet_ecosystem_p26_e2e_${runDate}_${runSuffix}"
$redisPrefix = "p26e2e_${runSuffix}_"
$apiContainer = "p26-e2e-api-${runSuffix}"
$configCache = "/tmp/p26-e2e-config-${runSuffix}.php"
$routesCache = "/tmp/p26-e2e-routes-${runSuffix}.php"
$apiPort = Get-Random -Minimum 8101 -Maximum 8200
$adminPort = 8500 + (Get-Random -Minimum 1 -Maximum 400)
$customerPort = 8900 + (Get-Random -Minimum 1 -Maximum 400)
$agentPort = 9300 + (Get-Random -Minimum 1 -Maximum 400)
$network = 'p08-isolated-20261028_wallet_test'
$postgres = 'p08-isolated-20261028-postgres-1'
$redis = 'p08-isolated-20261028-redis-1'
$apiImage = "wallet-ecosystem-api:p26-e2e-$runSuffix"
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$apiRoot = Join-Path $root 'wallet-ecosystem\services\api'
$adminRoot = Join-Path $root 'wallet-ecosystem\apps\admin'
$customerRoot = Join-Path $root 'wallet-ecosystem\apps\customer'
$agentRoot = Join-Path $root 'wallet-ecosystem\apps\agent'
$flutterWebServer = Join-Path $PSScriptRoot 'serve-p26-flutter-web.cjs'
$fixtureEvent = 'f2600000-0000-7000-8000-000000000002'
$fixtureOrg = 'f2600000-0000-7000-8000-000000000001'
$fixtureCompCategory = 'f2600000-0000-7000-8000-000000000003'
$fixtureFreeCategory = 'f2600000-0000-7000-8000-000000000004'
$fixtureGate = 'f2600000-0000-7000-8000-000000000005'
$fixtureDatabaseCreated = $false
$fixtureImageBuilt = $false
$apiStarted = $false
$appProcesses = [System.Collections.Generic.List[System.Diagnostics.Process]]::new()

if ($NativeOnly -and -not $NativeEventJourney) {
    throw 'NativeOnly requires NativeEventJourney; use it only for the isolated native Customer/Agent journey.'
}
if ($EventsFoundationRegression -and ($NativeCustomerLogin -or $NativeEventJourney -or $NativeOnly -or $FocusedCustomerLogin -or $Headed)) {
    throw 'EventsFoundationRegression is an isolated API-only run; do not combine it with browser/device switches.'
}

function Get-ContainerEnvironment([string]$Name) {
    $raw = & docker inspect $Name
    if ($LASTEXITCODE -ne 0) { throw "Cannot inspect required isolated container: $Name" }
    $container = @($raw | ConvertFrom-Json)[0]
    if (-not $container.State.Running) { throw "Required isolated container is not running: $Name" }
    return $container
}

function Get-EnvironmentValue($Container, [string]$Key) {
    $prefix = "$Key="
    $entry = @($Container.Config.Env | Where-Object { $_.StartsWith($prefix, [StringComparison]::Ordinal) }) | Select-Object -First 1
    if ($null -eq $entry) { return '' }
    return $entry.Substring($prefix.Length)
}

function Assert-PortAvailable([int]$Port) {
    $listener = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    if ($listener) { throw "Selected P26 E2E port is already in use: $Port" }
}

function Invoke-IsolatedArtisan([string[]]$ArtisanArgs, [string]$PrivatePem, [string]$AppKey, [string]$DbPassword, [string]$AdminPassword, [string]$Pin, [string]$Totp) {
    $args = @('run', '--rm', '--network', $network, '-w', '/var/www/html')
    $envValues = @{
        APP_ENV='testing'; APP_DEBUG='false'; APP_KEY=$AppKey; APP_URL="http://127.0.0.1:$apiPort"
        APP_CONFIG_CACHE=$configCache
        APP_ROUTES_CACHE=$routesCache
        PAYMENT_LIVE_CALLBACK_ENABLED='false'
        AUDIT_HMAC_KEY='p26-e2e-only-audit-key-not-production'; DB_CONNECTION='platform'
        IDENTITY_IP_HMAC_SECRET=$identityIpSecret
        DB_HOST=$postgres; DB_PORT='5432'; DB_DATABASE=$databaseName; DB_USERNAME='wallet_app'; DB_PASSWORD=$DbPassword
        P26_DISPOSABLE_DATABASE=$databaseName
        CACHE_STORE='array'; QUEUE_CONNECTION='sync'; SESSION_DRIVER='array'; REDIS_CLIENT='predis'
        REDIS_HOST=$redis; REDIS_PORT='6379'; REDIS_PREFIX=$redisPrefix; REDIS_IDENTITY_DB='4'
        CORS_ALLOWED_ORIGINS="http://127.0.0.1:$adminPort,http://127.0.0.1:$customerPort,http://127.0.0.1:$agentPort"
        IDENTITY_JWT_PRIVATE_KEY=$PrivatePem; P26_E2E_FIXTURE_APPROVALS='1'
        P26_E2E_EVENT_ID=$fixtureEvent; P26_E2E_ORGANISATION_ID=$fixtureOrg
        P26_E2E_CATEGORY_IDS="$fixtureCompCategory,$fixtureFreeCategory"
        P26_E2E_EXTRA_EVENT_ID='f2600000-0000-7000-8000-000000000006'
        P26_E2E_EXTRA_CATEGORY_IDS='f2600000-0000-7000-8000-000000000007'
        P26_E2E_ADMIN_PASSWORD=$AdminPassword; P26_E2E_ADMIN_TOTP_SECRET=$Totp
        P26_E2E_CUSTOMER_PIN=$Pin; P26_E2E_OPERATOR_PIN=$Pin
    }
    foreach ($key in $envValues.Keys) { $args += @('--env', "$key=$($envValues[$key])") }
    if ($ArtisanArgs[0] -eq '__run_php') {
        $args += @($apiImage, 'php') + $ArtisanArgs[1..($ArtisanArgs.Length - 1)]
    } else {
        $args += @($apiImage, 'php', 'artisan') + $ArtisanArgs
    }
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = & docker @args 2>&1 } finally { $ErrorActionPreference = $previousErrorAction }
    if ($LASTEXITCODE -ne 0) {
        $safeFailure = (@($output | Select-Object -Last 30) -join "`n") -replace 'password=[^\s]+', 'password=[redacted]' -replace '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', '[email]' -replace '\+?[0-9]{8,15}', '[phone]' -replace '\b[A-Za-z0-9_-]{40,}\b', '[opaque-secret]'
        $commandLabel = if ($ArtisanArgs[0] -eq '__run_php') { 'php ' + ($ArtisanArgs[1..($ArtisanArgs.Length - 1)] -join ' ') } else { 'php artisan ' + ($ArtisanArgs -join ' ') }
        throw "Isolated API command failed: $commandLabel`: $safeFailure"
    }
    return $output
}

function Wait-HttpReady([string]$Url, [string]$Label, [int]$TimeoutSeconds = 120) {
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    while ([DateTime]::UtcNow -lt $deadline) {
        try {
            $response = Invoke-WebRequest -Uri $Url -TimeoutSec 3 -UseBasicParsing
            if ($response.StatusCode -eq 200) { return }
        } catch { }
        Start-Sleep -Milliseconds 500
    }
    throw "$Label did not become ready: $Url"
}

function Build-IsolatedFlutterWeb([string]$Directory, [string]$Label, [string[]]$Defines) {
    $bundlePath = Join-Path $Directory 'build\web\main.dart.js'
    $sourceFiles = @(Get-ChildItem (Join-Path $Directory 'lib') -Filter '*.dart' -Recurse -File)
    $sourceFiles += @(Get-ChildItem (Join-Path $root 'wallet-ecosystem\packages\design-system\lib') -Filter '*.dart' -Recurse -File)
    $sourceFiles += @(Get-Item (Join-Path $Directory 'pubspec.yaml'))
    $sourceFiles += @(Get-Item (Join-Path $Directory 'pubspec.lock'))
    $latestSource = ($sourceFiles | Measure-Object -Property LastWriteTimeUtc -Maximum).Maximum
    $bundleMatchesApi = $false
    if (Test-Path -LiteralPath $bundlePath) {
        $bundleMatchesApi = [bool](Select-String -LiteralPath $bundlePath -SimpleMatch $apiUrl -Quiet)
    }
    if ((Test-Path -LiteralPath $bundlePath) -and $bundleMatchesApi -and
        (Get-Item -LiteralPath $bundlePath).LastWriteTimeUtc -ge $latestSource) {
        Write-Output "$Label Flutter web bundle reused: current source timestamp and isolated API URL verified."
        return
    }

    Push-Location $Directory
    try {
        $arguments = @('build', 'web', '--release') + $Defines
        & flutter @arguments
        if ($LASTEXITCODE -ne 0) { throw "$Label Flutter web build failed with exit code $LASTEXITCODE." }
    } finally { Pop-Location }
}

function Write-P26TestSummary([string]$Label, [string[]]$Output) {
    $summary = @($Output | Where-Object { $_ -match '^\s*(Tests:|OK \(|FAILURES!|ERRORS!)' } | Select-Object -Last 1)
    if ($summary.Count -gt 0) { Write-Output "$Label PHPUnit: $($summary[0].Trim())" }
    else { Write-Output "$Label PHPUnit completed; summary line was not emitted by the configured test runner." }
    foreach ($line in @($Output | Where-Object { $_ -match '(?i)warning|deprecat|risky' })) {
        $safeLine = [string]$line -replace 'password=[^\s]+', 'password=[redacted]' -replace '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', '[email]' -replace '\+?[0-9]{8,15}', '[phone]' -replace '\b[0-9]{6}\b', '[code]' -replace '\b[A-Za-z0-9_-]{40,}\b', '[opaque-secret]'
        Write-Output "$Label test diagnostic: $safeLine"
    }
    $warningStart = -1
    for ($index = 0; $index -lt $Output.Length; $index++) {
        if ($Output[$index] -match '^\s*WARNINGS\s+') { $warningStart = $index; break }
    }
    if ($warningStart -ge 0) {
        $limit = [Math]::Min($Output.Length - 1, $warningStart + 79)
        for ($index = $warningStart; $index -le $limit; $index++) {
            $safeLine = [string]$Output[$index] -replace 'password=[^\s]+', 'password=[redacted]' -replace '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', '[email]' -replace '\+?[0-9]{8,15}', '[phone]' -replace '\b[0-9]{6}\b', '[code]' -replace '\b[A-Za-z0-9_-]{40,}\b', '[opaque-secret]'
            Write-Output "$Label warning detail: $safeLine"
        }
    }
}

try {
    if ($databaseName -notmatch '^wallet_ecosystem_p26_e2e_[0-9]{8}_[a-f0-9]{8}$') { throw 'Unsafe database name rejected.' }
    if (-not $EventsFoundationRegression) {
        foreach ($port in @($apiPort, $adminPort, $customerPort, $agentPort)) { Assert-PortAvailable $port }
    }

    & docker build --quiet --target test --tag $apiImage $apiRoot 2>$null
    if ($LASTEXITCODE -ne 0) { throw 'Could not build the uniquely tagged P26 API test image from the current workspace.' }
    $fixtureImageBuilt = $true

    $pgContainer = Get-ContainerEnvironment $postgres
    $redisContainer = Get-ContainerEnvironment $redis
    if ((-not ($pgContainer.NetworkSettings.Networks.PSObject.Properties.Name -contains $network)) -or
        (-not ($redisContainer.NetworkSettings.Networks.PSObject.Properties.Name -contains $network))) {
        throw 'P26 E2E requires the verified isolated PostgreSQL/Redis network.'
    }
    $dbPassword = Get-EnvironmentValue $pgContainer 'POSTGRES_PASSWORD'
    if (-not $dbPassword) {
        $passwordFile = Get-EnvironmentValue $pgContainer 'POSTGRES_PASSWORD_FILE'
        if ($passwordFile) {
            $dbPassword = (& docker exec $postgres cat $passwordFile | Out-String).Trim()
        }
    }
    if (-not $dbPassword) {
        $devApiContainer = Get-ContainerEnvironment 'wallet-ecosystem-api-1'
        $dbPassword = Get-EnvironmentValue $devApiContainer 'DB_PASSWORD'
        if (-not $dbPassword) { throw 'Isolated PostgreSQL credentials are unavailable; refusing to guess.' }
        $passwordCheck = & docker exec -e "PGPASSWORD=$dbPassword" $postgres psql -h 127.0.0.1 -U wallet_app -d postgres -Atc 'SELECT current_user'
        if ($LASTEXITCODE -ne 0 -or $passwordCheck.Trim() -ne 'wallet_app') {
            throw 'The existing local application credential does not authenticate to the isolated PostgreSQL service.'
        }
    }

    $existing = & docker exec -e "PGPASSWORD=$dbPassword" $postgres psql -h 127.0.0.1 -U wallet_app -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname='$databaseName'"
    if ($LASTEXITCODE -ne 0 -or $existing) { throw 'Unique P26 database already exists or inventory check failed; refusing schema operations.' }
    & docker exec -e "PGPASSWORD=$dbPassword" $postgres createdb -h 127.0.0.1 -U wallet_app $databaseName
    if ($LASTEXITCODE -ne 0) { throw 'Could not create the unique isolated P26 database.' }
    $fixtureDatabaseCreated = $true

    $privatePem = [string]::Join("`n", @(& openssl.exe ecparam -name prime256v1 -genkey -noout)).Trim()
    if ($LASTEXITCODE -ne 0 -or $privatePem -notmatch 'BEGIN EC PRIVATE KEY') {
        throw 'Could not create an ephemeral ECDSA test signing key.'
    }
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $randomBytes = New-Object byte[] 32
    $rng.GetBytes($randomBytes)
    $appKey = 'base64:' + [Convert]::ToBase64String($randomBytes)
    $rng.GetBytes($randomBytes)
    $adminPassword = [Convert]::ToBase64String($randomBytes) + 'A9!'
    $rng.GetBytes($randomBytes)
    $identityIpSecret = [Convert]::ToBase64String($randomBytes)
    $rng.Dispose()
    $pin = '726194'
    $totpSecret = 'JBSWY3DPEHPK3PXP'

    [void](Invoke-IsolatedArtisan @('p26:e2e:assert-database', '--no-interaction') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret)
    [void](Invoke-IsolatedArtisan @('platform:bootstrap-schemas', '--no-interaction') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret)
    [void](Invoke-IsolatedArtisan @('migrate', '--force', '--no-interaction') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret)
    $seedOutput = Invoke-IsolatedArtisan @('db:seed', '--class=Database\Seeders\P26BrowserE2ESeeder', '--no-interaction') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret
    $fixtureLine = @($seedOutput | Where-Object { $_ -match '^\{"event_id"' }) | Select-Object -Last 1
    if (-not $fixtureLine) { throw 'Seeder did not return safe fixture identifiers.' }
    $fixtureIds = $fixtureLine | ConvertFrom-Json

    $corsTestOutput = Invoke-IsolatedArtisan @('test', '--no-coverage', '--colors=never', '--display-warnings', 'tests/Feature/ApiCorsTest.php') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret
    Write-P26TestSummary 'Central CORS' $corsTestOutput
    $mfaTestOutput = Invoke-IsolatedArtisan @('test', '--no-coverage', '--colors=never', '--display-warnings', 'app/Modules/Identity/Tests/Feature/Mfa/MfaTest.php') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret
    Write-P26TestSummary 'Identity MFA' $mfaTestOutput

    if ($EventsFoundationRegression) {
        $eventsTestArgs = @('test', '--no-coverage', '--colors=never', '--display-warnings')
        if (-not [string]::IsNullOrWhiteSpace($EventsTestFilter)) { $eventsTestArgs += @('--filter', $EventsTestFilter) }
        $eventsTestArgs += @(
            'tests/Feature/Modules/Events/EventDraftFoundationTest.php',
            'tests/Feature/Modules/Events/OleKardFinancialTermsWorkflowTest.php'
        )
        $eventsTestOutput = Invoke-IsolatedArtisan $eventsTestArgs $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret
        Write-P26TestSummary 'Events foundation' $eventsTestOutput
        $eventsUnitOutput = Invoke-IsolatedArtisan @('test', '--no-coverage', '--colors=never', '--display-warnings', 'tests/Unit/Modules/Events') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret
        Write-P26TestSummary 'Events unit contracts' $eventsUnitOutput
        $cancellationRaceOutput = Invoke-IsolatedArtisan @('__run_php', 'tests/Concurrency/P26FreeRegistrationPostgresTest.php') $privatePem $appKey $dbPassword $adminPassword $pin $totpSecret
        Write-Output "P26 separate-session PostgreSQL race harness: $(($cancellationRaceOutput | Where-Object { $_ -match '"status":"passed"' } | Select-Object -Last 1))"
        $success = $true
        return
    }

    $runtimeArgs = @('run', '-d', '--name', $apiContainer, '--network', $network, '-p', "127.0.0.1:${apiPort}:8000", '-w', '/var/www/html')
    $runtimeEnv = @{
        APP_ENV='testing'; APP_DEBUG='false'; APP_KEY=$appKey; APP_URL="http://127.0.0.1:$apiPort"
        APP_CONFIG_CACHE=$configCache
        APP_ROUTES_CACHE=$routesCache
        LOG_CHANNEL='stderr'; LOG_LEVEL='error'
        PAYMENT_LIVE_CALLBACK_ENABLED='false'
        AUDIT_HMAC_KEY='p26-e2e-only-audit-key-not-production'; DB_CONNECTION='platform'
        IDENTITY_IP_HMAC_SECRET=$identityIpSecret
        DB_HOST=$postgres; DB_PORT='5432'; DB_DATABASE=$databaseName; DB_USERNAME='wallet_app'; DB_PASSWORD=$dbPassword
        CACHE_STORE='array'; QUEUE_CONNECTION='sync'; SESSION_DRIVER='array'; REDIS_CLIENT='predis'
        REDIS_HOST=$redis; REDIS_PORT='6379'; REDIS_PREFIX=$redisPrefix; REDIS_IDENTITY_DB='4'
        CORS_ALLOWED_ORIGINS="http://127.0.0.1:$adminPort,http://127.0.0.1:$customerPort,http://127.0.0.1:$agentPort"
        IDENTITY_JWT_PRIVATE_KEY=$privatePem; P26_E2E_FIXTURE_APPROVALS='1'
        P26_E2E_EVENT_ID=$fixtureEvent; P26_E2E_ORGANISATION_ID=$fixtureOrg
        P26_E2E_CATEGORY_IDS="$fixtureCompCategory,$fixtureFreeCategory"
        P26_E2E_EXTRA_EVENT_ID='f2600000-0000-7000-8000-000000000006'
        P26_E2E_EXTRA_CATEGORY_IDS='f2600000-0000-7000-8000-000000000007'
    }
    foreach ($key in $runtimeEnv.Keys) { $runtimeArgs += @('--env', "$key=$($runtimeEnv[$key])") }
    $runtimeArgs += @($apiImage, 'sh', '-lc', 'php artisan config:cache --no-interaction && php artisan p26:e2e:assert-database --no-interaction && php artisan serve --no-reload --host=0.0.0.0 --port=8000')
    $null = & docker @runtimeArgs
    if ($LASTEXITCODE -ne 0) { throw 'Could not start isolated P26 API container.' }
    $apiStarted = $true
    $apiUrl = "http://127.0.0.1:$apiPort"
    Wait-HttpReady "$apiUrl/up" 'P26 isolated API'
    $adminOrigin = "http://127.0.0.1:$adminPort"
    $preflight = Invoke-WebRequest -Uri "$apiUrl/v1/auth/mfa/verify" -Method Options -Headers @{
        Origin=$adminOrigin; 'Access-Control-Request-Method'='POST'; 'Access-Control-Request-Headers'='content-type,x-correlation-id'
    } -UseBasicParsing -TimeoutSec 10
    if ($preflight.Headers['Access-Control-Allow-Origin'] -ne $adminOrigin) {
        throw 'P26 isolated API MFA preflight did not allow the exact Admin browser origin.'
    }
    $corsErrorStatus = 0
    $corsErrorAllowOrigin = ''
    try {
        $corsError = Invoke-WebRequest -Uri "$apiUrl/v1/auth/mfa/verify" -Method Post -Headers @{
            Origin=$adminOrigin; 'X-Correlation-ID'='p26-e2e-cors-error-probe'; 'Content-Type'='application/json'; Accept='application/json'
        } -Body '{"mfa_token":"p26-invalid-test-token","method":"totp","code":"000000"}' -UseBasicParsing -TimeoutSec 10
        $corsErrorStatus = [int]$corsError.StatusCode
        $corsErrorAllowOrigin = [string]$corsError.Headers['Access-Control-Allow-Origin']
    } catch {
        $errorResponse = $_.Exception.Response
        if (-not $errorResponse) { throw }
        $corsErrorStatus = [int]$errorResponse.StatusCode
        $corsErrorAllowOrigin = [string]$errorResponse.Headers.Get('Access-Control-Allow-Origin')
    }
    if ($corsErrorStatus -ne 401 -or $corsErrorAllowOrigin -ne $adminOrigin) {
        throw "P26 isolated API MFA error response failed CORS verification (HTTP $corsErrorStatus, allow-origin present=$($corsErrorAllowOrigin.Length -gt 0))."
    }
    $runtimeDatabase = Invoke-RestMethod -Uri "$apiUrl/api/p26-e2e/database" -TimeoutSec 10
    $runtimeData = $runtimeDatabase.data
    if (($runtimeData.database -ne $databaseName) -or ($runtimeData.configured_database -ne $databaseName) -or
        ($runtimeData.host -ne $postgres) -or ([string]$runtimeData.port -ne '5432')) {
        throw ("HTTP runtime database mismatch: actual={0}; configured={1}; endpoint={2}:{3}." -f
            $runtimeData.database, $runtimeData.configured_database, $runtimeData.host, $runtimeData.port)
    }
    Write-Output "P26 HTTP runtime database verified: $($runtimeData.database) via isolated PostgreSQL service."

    if ($NativeCustomerLogin) {
        $deviceList = @(& flutter devices 2>&1)
        if ($LASTEXITCODE -ne 0 -or -not ($deviceList -match 'emulator-5554')) {
            throw 'P26 native customer login requested, but the expected Android emulator is not discoverable by Flutter.'
        }
        Push-Location $customerRoot
        try {
            & flutter test integration_test/login_connection_test.dart -d emulator-5554 `
                --dart-define=API_BASE_URL=http://10.0.2.2:$apiPort `
                --dart-define=RUN_LOCAL_LOGIN_SMOKE=true `
                --dart-define=RUN_LOCAL_PIN_SMOKE=true `
                --dart-define=P26_E2E_CUSTOMER_PHONE=+26771009991 `
                --dart-define=P26_E2E_CUSTOMER_PIN=$pin
            if ($LASTEXITCODE -ne 0) { throw 'P26 native Customer login integration test failed.' }
            Write-Output 'P26 native Customer login/PIN integration passed on emulator-5554 against the isolated API.'
        } finally { Pop-Location }
    }

    if (-not $NativeOnly) {
    $previousApiUrl = $env:NEXT_PUBLIC_API_URL
    $env:NEXT_PUBLIC_API_URL = $apiUrl
    $adminProcess = Start-Process -FilePath 'node.exe' -ArgumentList @('node_modules/next/dist/bin/next', 'dev', '-p', "$adminPort") -WorkingDirectory $adminRoot -PassThru -WindowStyle Hidden
    $appProcesses.Add($adminProcess)
    $env:NEXT_PUBLIC_API_URL = $previousApiUrl

    # Build synchronously so each browser app embeds this run's isolated API
    # URL. Serve the exact build outputs; do not let a stale Flutter web build
    # silently point back to the developer API.
    Build-IsolatedFlutterWeb $customerRoot 'Customer' @("--dart-define=API_BASE_URL=$apiUrl", '--dart-define=P26_E2E_ENABLE_WEB_SEMANTICS=true')
    Build-IsolatedFlutterWeb $agentRoot 'Agent' @("--dart-define=API_BASE_URL=$apiUrl", '--dart-define=P26_E2E_ENABLE_WEB_SEMANTICS=true')
    $customerWebRoot = Join-Path $customerRoot 'build\web'
    $agentWebRoot = Join-Path $agentRoot 'build\web'
    $customerProcess = Start-Process -FilePath 'node.exe' -ArgumentList @($flutterWebServer, $customerWebRoot, "$customerPort") -PassThru -WindowStyle Hidden
    $appProcesses.Add($customerProcess)
    $agentProcess = Start-Process -FilePath 'node.exe' -ArgumentList @($flutterWebServer, $agentWebRoot, "$agentPort") -PassThru -WindowStyle Hidden
    $appProcesses.Add($agentProcess)
    Wait-HttpReady "http://127.0.0.1:$adminPort/login" 'Admin UI'
    Wait-HttpReady "http://127.0.0.1:$customerPort/login" 'Customer Flutter web UI' 300
    Wait-HttpReady "http://127.0.0.1:$agentPort/login" 'Agent Flutter web UI' 300

    $env:P26_ADMIN_URL = "http://127.0.0.1:$adminPort"
    $env:P26_API_BASE_URL = $apiUrl
    $env:P26_CUSTOMER_URL = "http://127.0.0.1:$customerPort"
    $env:P26_AGENT_URL = "http://127.0.0.1:$agentPort"
    $env:P26_E2E_ADMIN_EMAIL = 'admin@p26-e2e.invalid'
    $env:P26_E2E_ADMIN_PASSWORD = $adminPassword
    $env:P26_E2E_ADMIN_TOTP_SECRET = $totpSecret
    $env:P26_E2E_CUSTOMER_PIN = $pin
    $env:P26_E2E_OPERATOR_PIN = $pin
    $env:P26_E2E_EVENT_ID = $fixtureEvent
    $env:P26_E2E_ORGANISATION_ID = $fixtureOrg
    $env:P26_E2E_COMPLIMENTARY_CATEGORY_ID = $fixtureCompCategory
    $env:P26_E2E_FREE_CATEGORY_ID = $fixtureFreeCategory
    $env:P26_E2E_GATE_ID = $fixtureGate
    $env:P26_E2E_CUSTOMER_ID = [string]$fixtureIds.customer_id
    $env:P26_E2E_OVERFLOW_CUSTOMER_ID = [string]$fixtureIds.overflow_customer_id

    Push-Location $adminRoot
    try {
        $playwrightArgs = @('playwright', 'test', '--config=playwright.p26.config.ts')
        if ($FocusedCustomerLogin) { $playwrightArgs += @('--grep', 'P26 focused customer login') }
        if ($Headed) { $playwrightArgs += '--headed' }
        & npx.cmd @playwrightArgs
        if ($LASTEXITCODE -ne 0) { throw 'P26 isolated Chromium journey failed; its database is preserved for diagnosis.' }
    } finally { Pop-Location }

    if ($FocusedCustomerLogin) {
        Write-Output "P26 focused Customer login passed through Chromium against isolated database $databaseName."
    } else {
        $counts = & docker exec -e "PGPASSWORD=$dbPassword" $postgres psql -h 127.0.0.1 -U wallet_app -d $databaseName -Atc "SELECT (SELECT count(*) FROM community_services.event_free_registrations WHERE event_id='$fixtureEvent') || '|' || (SELECT count(*) FROM community_services.event_tickets WHERE event_id='$fixtureEvent') || '|' || (SELECT count(*) FROM community_services.event_attendance WHERE event_id='$fixtureEvent') || '|' || (SELECT count(*) FROM community_services.event_attendance_audit WHERE event_id='$fixtureEvent' AND action='admitted') || '|' || (SELECT count(*) FROM community_services.event_draft_audit WHERE event_id='$fixtureEvent' AND action='free_entitlement_issued') || '|' || (SELECT count(*) FROM community_services.event_invitation_audit WHERE event_id='$fixtureEvent' AND action IN ('issued','redeemed','revoked')) || '|' || (SELECT count(*) FROM payment.payment_intents) || '|' || (SELECT count(*) FROM payment.payment_attempts) || '|' || (SELECT count(*) FROM payment.payment_refunds) || '|' || (SELECT count(*) FROM ledger.journals) || '|' || (SELECT count(*) FROM payment.payout_settlement_records) || '|' || (SELECT count(*) FROM reconciliation.settlement_batches)"
        if ($LASTEXITCODE -ne 0) { throw 'Could not verify persisted Events and financial rows.' }
        if ($counts.Trim() -ne '3|3|1|1|3|5|0|0|0|0|0|0') { throw "Unexpected persisted P26 E2E counts: $($counts.Trim())" }
        Write-Output "P26 isolated PostgreSQL verification: registrations|tickets|attendance|admission-audit|free-issuance-audit|invitation-audit|payment-intents|payment-attempts|refunds|journals|payout-records|settlement-batches = $($counts.Trim()) (expected 3|3|1|1|3|5|0|0|0|0|0|0)."
        Write-Output "P26 full journey passed. Isolated database $databaseName was created by this run and is now eligible for exact-name cleanup."
    }
    }
    if ($NativeEventJourney) {
        $deviceList = @(& flutter devices 2>&1)
        if ($LASTEXITCODE -ne 0 -or -not ($deviceList -match 'emulator-5554')) {
            throw 'P26 native event journey requested, but emulator-5554 is not discoverable by Flutter.'
        }
        Push-Location $customerRoot
        try {
            & flutter test integration_test/p26_native_event_test.dart -d emulator-5554 `
                --dart-define=API_BASE_URL=http://10.0.2.2:$apiPort `
                --dart-define=P26_E2E_CUSTOMER_PHONE=+26771009991 `
                --dart-define=P26_E2E_CUSTOMER_PIN=$pin `
                --dart-define=P26_E2E_NATIVE_EVENT_ID=$($fixtureIds.native_event_id) `
                --dart-define=P26_E2E_NATIVE_CATEGORY_ID=$($fixtureIds.native_category_id)
            if ($LASTEXITCODE -ne 0) { throw 'P26 native Customer registration/ticket journey failed.' }
        } finally { Pop-Location }
        Push-Location $agentRoot
        try {
            & flutter test integration_test/p26_native_gate_test.dart -d emulator-5554 `
                --dart-define=API_BASE_URL=http://10.0.2.2:$apiPort `
                --dart-define=P26_E2E_OPERATOR_PHONE=+26771009993 `
                --dart-define=P26_E2E_OPERATOR_PIN=$pin `
                --dart-define=P26_E2E_NATIVE_EVENT_ID=$($fixtureIds.native_event_id) `
                --dart-define=P26_E2E_NATIVE_GATE_ID=$($fixtureIds.native_gate_id) `
                --dart-define=P26_E2E_NATIVE_WRONG_GATE_ID=$($fixtureIds.native_wrong_gate_id)
            if ($LASTEXITCODE -ne 0) { throw 'P26 native Agent gate journey failed.' }
        } finally { Pop-Location }
        $nativeCounts = & docker exec -e "PGPASSWORD=$dbPassword" $postgres psql -h 127.0.0.1 -U wallet_app -d $databaseName -Atc "SELECT (SELECT count(*) FROM community_services.event_free_registrations WHERE event_id='$($fixtureIds.native_event_id)') || '|' || (SELECT count(*) FROM community_services.event_tickets WHERE event_id='$($fixtureIds.native_event_id)') || '|' || (SELECT count(*) FROM community_services.event_attendance WHERE event_id='$($fixtureIds.native_event_id)') || '|' || (SELECT count(*) FROM community_services.event_attendance_audit WHERE event_id='$($fixtureIds.native_event_id)' AND action='admitted') || '|' || (SELECT count(*) FROM community_services.event_draft_audit WHERE event_id='$($fixtureIds.native_event_id)' AND action='free_entitlement_issued') || '|' || (SELECT count(*) FROM payment.payment_intents) || '|' || (SELECT count(*) FROM payment.payment_attempts) || '|' || (SELECT count(*) FROM wallet.wallet_capture_records) || '|' || (SELECT count(*) FROM payment.payment_refunds) || '|' || (SELECT count(*) FROM ledger.journals) || '|' || (SELECT count(*) FROM payment.payout_settlement_records) || '|' || (SELECT count(*) FROM reconciliation.settlement_batches)"
        if ($LASTEXITCODE -ne 0) { throw 'Could not verify persisted native P26 journey and financial rows.' }
        if ($nativeCounts.Trim() -ne '2|2|1|1|1|0|0|0|0|0|0|0') { throw "Unexpected persisted native P26 journey counts: $($nativeCounts.Trim())" }
        Write-Output "P26 native Customer/Agent assertions passed: registrations|tickets|attendance|admission-audit|free-issuance-audit|payment-intents|payment-attempts|wallet-captures|refunds|journals|payout-records|settlement-batches = $($nativeCounts.Trim())."
    }
    $success = $true
}
catch {
    if ($apiStarted) {
        $apiLogLines = @()
        try { $apiLogLines = @(& docker logs --tail 500 $apiContainer 2>$null) } catch { }
        foreach ($line in $apiLogLines) {
            try {
                $record = $line | ConvertFrom-Json -ErrorAction Stop
                if ($record.message -match 'Module database connection unavailable|Module connection unavailable') {
                    $details = $record.context.exception -replace 'password=[^ ]+', 'password=[redacted]' -replace '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', '[email]'
                    Write-Output ("P26 API diagnostic: {0}; connection={1}; cause={2}" -f $record.message, $record.context.connection_name, $details)
                } elseif ($record.level_name -eq 'ERROR' -and $record.context.correlation_id -eq 'p26-e2e-auth-probe') {
                    $exception = $record.context.exception
                    $errorClass = if ($exception.class) { [string]$exception.class } else { 'unknown' }
                    $errorMessage = if ($exception.message) { [string]$exception.message } else { [string]$record.message }
                    $errorMessage = $errorMessage -replace 'password=[^ ]+', 'password=[redacted]' -replace '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', '[email]' -replace '\+?[0-9]{8,15}', '[phone]' -replace '\b[A-Za-z0-9_-]{40,}\b', '[opaque-secret]'
                    Write-Output ("P26 API diagnostic: {0}; type={1}; cause={2}" -f $record.message, $errorClass, $errorMessage)
                }
            } catch { }
        }
    }
    Write-Error ("P26 isolated harness stopped at {0}: {1}" -f $_.InvocationInfo.PositionMessage, $_.Exception.Message)
    throw
}
finally {
    if ($hadNoColorSetting) { $env:NO_COLOR = $savedNoColorSetting }
    else { Remove-Item Env:NO_COLOR -ErrorAction SilentlyContinue }
    foreach ($process in $appProcesses) {
        if (-not $process.HasExited) { try { & taskkill.exe /PID $process.Id /T /F *> $null } catch { } }
    }
    if ($apiStarted) { & docker rm -f $apiContainer *> $null }
    if ($fixtureImageBuilt -and $apiImage -match '^wallet-ecosystem-api:p26-e2e-[a-f0-9]{8}$') {
        try { & docker image rm $apiImage *> $null } catch { }
    }
    if ($success -and $fixtureDatabaseCreated -and $databaseName -match '^wallet_ecosystem_p26_e2e_[0-9]{8}_[a-f0-9]{8}$') {
        & docker exec -e "PGPASSWORD=$dbPassword" $postgres dropdb -h 127.0.0.1 -U wallet_app --if-exists $databaseName
        if ($LASTEXITCODE -ne 0) { Write-Warning 'Journey passed but its uniquely named isolated test database cleanup failed.' }
    } elseif ($fixtureDatabaseCreated) {
        Write-Warning "Failure artifacts are preserved in isolated database $databaseName. Do not reuse it; inspect then drop this exact name only."
    }
}
