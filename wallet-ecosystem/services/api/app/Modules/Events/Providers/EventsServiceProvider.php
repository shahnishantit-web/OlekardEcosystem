<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Infrastructure\DenyEventDraftAuthorizer;
use Illuminate\Support\ServiceProvider;

final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventDraftAuthorizer::class, DenyEventDraftAuthorizer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
    }
}
