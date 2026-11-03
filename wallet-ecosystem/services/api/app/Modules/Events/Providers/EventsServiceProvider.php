<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use App\Modules\Events\Application\EventPaymentConfirmationConsumer;
use App\Modules\Events\Console\BootstrapEventAssignmentAuthority;
use App\Modules\Events\Console\ExpireEventInventoryHolds;
use App\Modules\Events\Domain\Contracts\BootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\Contracts\EventPublicationReadiness;
use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Events\Domain\Contracts\EventWorkflowAuthorizer;
use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Events\Infrastructure\ConfiguredEventsPaymentServiceActorProvider;
use App\Modules\Events\Infrastructure\DenyBootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Infrastructure\DenyComplimentaryAllocationApproval;
use App\Modules\Events\Infrastructure\DenyEventPricingContract;
use App\Modules\Events\Infrastructure\DenyEventPublicationReadiness;
use App\Modules\Events\Infrastructure\IdentityInvitationRecipientVerifier;
use App\Modules\Events\Infrastructure\PaidEventTicketPurposeResolver;
use App\Modules\Events\Infrastructure\ScopedEventDraftAuthorizer;
use App\Modules\Events\Infrastructure\ScopedEventWorkflowAuthorizer;
use App\Modules\Payment\Providers\PaymentServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

final class EventsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventDraftAuthorizer::class, ScopedEventDraftAuthorizer::class);
        $this->app->bind(EventWorkflowAuthorizer::class, ScopedEventWorkflowAuthorizer::class);
        $this->app->bind(EventPublicationReadiness::class, DenyEventPublicationReadiness::class);
        $this->app->bind(BootstrapApprovalEvidenceVerifier::class, DenyBootstrapApprovalEvidenceVerifier::class);
        $this->app->bind(ComplimentaryAllocationApprovalContract::class, DenyComplimentaryAllocationApproval::class);
        $this->app->bind(EventPricingContract::class, DenyEventPricingContract::class);
        $this->app->bind(InvitationRecipientVerifier::class, IdentityInvitationRecipientVerifier::class);
        $this->app->bind(EventsPaymentServiceActorProvider::class, ConfiguredEventsPaymentServiceActorProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/events.php'));
        $this->app->make(Dispatcher::class)
            ->listen('payment.confirmed', EventPaymentConfirmationConsumer::class);
        $this->app->booted(static function (): void {
            PaymentServiceProvider::registerPurposeResolver(
                'paid_event_ticket', PaidEventTicketPurposeResolver::class,
            );
        });
        $this->commands([BootstrapEventAssignmentAuthority::class, ExpireEventInventoryHolds::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('events:expire-inventory-holds')->everyMinute()->withoutOverlapping();
        });
    }
}
