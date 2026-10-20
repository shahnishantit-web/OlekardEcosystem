<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Jobs;

use App\Modules\Identity\Domain\Events\SessionExpiredEvent;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Two-phase session lifecycle job:
 * 1. Expire: active sessions past their TTL are transitioned to `expired` and
 *    emit `Identity.Session.Expired` atomically via the outbox.
 * 2. Purge: sessions with terminal statuses older than the retention window are deleted.
 * Active sessions are NEVER deleted in either pass.
 */
final class SessionCleanupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(ConfigurationSecurityService $config, EventPublisherInterface $events): void
    {
        $this->expireActiveSessions($config, $events);
        $this->purgeTerminalSessions($config);
    }

    private function expireActiveSessions(ConfigurationSecurityService $config, EventPublisherInterface $events): void
    {
        $now = now();

        $expired = Session::where('status', 'active')
            ->where('expires_at', '<', $now)
            ->get();

        foreach ($expired as $session) {
            DB::connection('platform')->transaction(function () use ($session, $events, $now): void {
                Session::where('id', $session->id)
                    ->where('status', 'active')
                    ->update(['status' => 'expired', 'updated_at' => $now]);

                $events->publish(new SessionExpiredEvent(
                    sessionId:    $session->id,
                    actorId:      $session->user_id,
                    correlationId: (string) Str::uuid(),
                    userId:       $session->user_id,
                    actorType:    $session->actor_type,
                ));
            });
        }

        if ($expired->count() > 0) {
            Log::info('identity.session.expire.completed', ['count' => $expired->count()]);
        }
    }

    private function purgeTerminalSessions(ConfigurationSecurityService $config): void
    {
        $retentionDays = $config->getSessionRetentionDays();
        $cutoff        = now()->subDays($retentionDays);

        $deleted = Session::whereIn('status', ['expired', 'revoked', 'logged_out'])
            ->where('updated_at', '<', $cutoff)
            ->delete();

        Log::info('identity.session.cleanup.completed', [
            'deleted'        => $deleted,
            'retention_days' => $retentionDays,
            'cutoff'         => $cutoff->toIso8601String(),
        ]);
    }
}
