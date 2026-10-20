<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Middleware stack (applied in bootstrap/app.php):
|   TrustProxies → SecurityHeaders → CorrelationId → RequestLogging
|   → Authentication → Idempotency → Throttle → ResponseEnvelope → ResponseLogging
|
| Module route files are registered exclusively by each module's ServiceProvider
| via $this->loadRoutesFrom(base_path('routes/modules/{module}.php')) per FR-176.
|
| Health probe routes (/health, /ready, /startup) are registered at the root
| level (no /api prefix) via bootstrap/app.php withRouting(then:…) per FR-052.
|
*/
