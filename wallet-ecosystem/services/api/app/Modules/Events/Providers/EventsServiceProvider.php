<?php

declare(strict_types=1);

namespace App\Modules\Events\Providers;

use App\Modules\Events\Application\EventCancellationNotificationListener;
use App\Modules\Events\Application\EventPaymentConfirmationConsumer;
use App\Modules\Events\Console\BootstrapEventAssignmentAuthority;
use App\Modules\Events\Console\ExpireEventInventoryHolds;
use App\Modules\Events\Domain\Contracts\BootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Domain\Contracts\ComplimentaryAllocationApprovalContract;
use App\Modules\Events\Domain\Contracts\EventCancellationNotificationDispatcher;
use App\Modules\Events\Domain\Contracts\EventDraftAuthorizer;
use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\Contracts\EventPublicationReadiness;
use App\Modules\Events\Domain\Contracts\EventsPaymentServiceActorProvider;
use App\Modules\Events\Domain\Contracts\EventWorkflowAuthorizer;
use App\Modules\Events\Domain\Contracts\InvitationRecipientVerifier;
use App\Modules\Events\Domain\Contracts\OrganiserVerificationContract;
use App\Modules\Events\Infrastructure\ConfiguredEventsPaymentServiceActorProvider;
use App\Modules\Events\Infrastructure\DatabaseComplimentaryAllocationApproval;
use App\Modules\Events\Infrastructure\DenyBootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Infrastructure\DenyEventPricingContract;
use App\Modules\Events\Infrastructure\DenyEventPublicationReadiness;
use App\Modules\Events\Infrastructure\DenyOrganiserVerification;
use App\Modules\Events\Infrastructure\IdentityInvitationRecipientVerifier;
use App\Modules\Events\Infrastructure\LocalDemoEventPricingContract;
use App\Modules\Events\Infrastructure\LocalDemoEventsPaymentServiceActorProvider;
use App\Modules\Events\Infrastructure\LocalPocBootstrapApprovalEvidenceVerifier;
use App\Modules\Events\Infrastructure\LocalPocEventPublicationReadiness;
use App\Modules\Events\Infrastructure\LocalPocOrganiserVerification;
use App\Modules\Events\Infrastructure\LocalPocEventNotificationDispatcher;
use App\Modules\Events\Infrastructure\NotificationServiceCancellationDispatcher;
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
        $this->app->bind(EventCancellationNotificationDispatcher::class, static function (): EventCancellationNotificationDispatcher {
            if (app()->environment('local') && config('events.poc_enabled') === true) {
                return new LocalPocEventNotificationDispatcher();
            }
            return app(NotificationServiceCancellationDispatcher::class);
        });
        $this->app->bind(EventWorkflowAuthorizer::class, ScopedEventWorkflowAuthorizer::class);
        $localPoc = app()->environment('local') && config('events.poc_enabled') === true;
        $this->app->bind(EventPublicationReadiness::class, $localPoc
            ? LocalPocEventPublicationReadiness::class : DenyEventPublicationReadiness::class);
        $this->app->bind(OrganiserVerificationContract::class, $localPoc
            ? LocalPocOrganiserVerification::class : DenyOrganiserVerification::class);
        $this->app->bind(BootstrapApprovalEvidenceVerifier::class, $localPoc
            ? LocalPocBootstrapApprovalEvidenceVerifier::class : DenyBootstrapApprovalEvidenceVerifier::class);
        $this->app->bind(ComplimentaryAllocationApprovalContract::class, DatabaseComplimentaryAllocationApproval::class);
        $this->app->bind(EventPricingContract::class, static function (): EventPricingContract {
            if (app()->environment('local') && (config('events.local_demo_pricing_enabled') === true
                || config('events.poc_enabled') === true)) {
                return new LocalDemoEventPricingContract();
            }

            return new DenyEventPricingContract();
        });
        $this->app->bind(InvitationRecipientVerifier::class, IdentityInvitationRecipientVerifier::class);
        $this->app->bind(EventsPaymentServiceActorProvider::class, static function (): EventsPaymentServiceActorProvider {
            if (app()->environment('local') && (config('events.local_demo_payment_fulfilment_enabled') === true
                || config('events.poc_enabled') === true)) {
                return new LocalDemoEventsPaymentServiceActorProvider();
            }

            return app(ConfiguredEventsPaymentServiceActorProvider::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Infrastructure/Migrations');
        $this->loadRoutesFrom(base_path('routes/modules/events.php'));
        $this->app->make(Dispatcher::class)
            ->listen('payment.confirmed', EventPaymentConfirmationConsumer::class);
        $this->app->make(Dispatcher::class)
            ->listen('Events.Cancellation.Effective', EventCancellationNotificationListener::class);
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
