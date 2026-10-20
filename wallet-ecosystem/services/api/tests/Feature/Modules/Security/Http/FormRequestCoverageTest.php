<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Ensures that all state-changing routes (POST, PUT, PATCH, DELETE) are guarded
 * by a FormRequest for input validation. FR-006.
 */
final class FormRequestCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function all_state_changing_routes_use_a_form_request(): void
    {
        /** @var Router $router */
        $router   = $this->app->make(Router::class);
        $routes   = $router->getRoutes();
        $unguarded = [];

        foreach ($routes as $route) {
            /** @var Route $route */
            $methods = array_filter($route->methods(), static fn ($m) => in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true));
            if (empty($methods)) {
                continue;
            }

            $action     = $route->getAction();
            $controller = $action['controller'] ?? null;
            if ($controller === null || !str_contains($controller, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $controller, 2);

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (!$reflection->hasMethod($method)) {
                continue;
            }

            $params  = $reflection->getMethod($method)->getParameters();
            $hasFormRequest = false;

            foreach ($params as $param) {
                $type = $param->getType();
                if (!($type instanceof ReflectionNamedType) || $type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();
                if (class_exists($typeName) && is_a($typeName, FormRequest::class, true)) {
                    $hasFormRequest = true;
                    break;
                }
            }

            if (!$hasFormRequest) {
                $unguarded[] = sprintf(
                    '[%s] %s → %s::%s',
                    implode('|', $methods),
                    $route->uri(),
                    $class,
                    $method
                );
            }
        }

        $this->assertEmpty(
            $unguarded,
            "The following state-changing routes lack a FormRequest parameter:\n".implode("\n", $unguarded)
        );
    }
}
