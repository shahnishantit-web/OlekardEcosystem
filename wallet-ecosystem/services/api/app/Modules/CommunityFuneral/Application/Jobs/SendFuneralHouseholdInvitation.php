<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application\Jobs;

use App\Modules\Notification\Domain\Contracts\SmsChannelInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SendFuneralHouseholdInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(private readonly string $invitationId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 240, 480];
    }

    public function handle(SmsChannelInterface $sms): void
    {
        $db = DB::connection('community_services');
        $invitation = $db->table('community_services.funeral_household_invitations')->where('id', $this->invitationId)->first();
        if ($invitation === null || in_array($invitation->status, ['sent', 'claimed', 'expired'], true)) {
            return;
        }
        if (now()->greaterThan($invitation->expires_at)) {
            $db->table('community_services.funeral_household_invitations')->where('id', $this->invitationId)
                ->update(['status' => 'expired', 'updated_at' => now()]);
            return;
        }

        $context = $db->table('community_services.funeral_collections as collection')
            ->join('community_services.funeral_communities as community', 'community.id', '=', 'collection.community_id')
            ->where('collection.id', $invitation->collection_id)
            ->first(['collection.purpose', 'collection.amount_minor', 'collection.currency_code', 'community.display_name as community_name', 'community.organisation_id']);
        if ($context === null) {
            $db->table('community_services.funeral_household_invitations')->where('id', $this->invitationId)
                ->update(['status' => 'failed', 'updated_at' => now()]);
            return;
        }

        $organisationName = DB::connection('identity')->table('identity.organisations')
            ->where('id', $context->organisation_id)->value('display_name') ?: 'OleKard community';
        $token = Crypt::decryptString((string) $invitation->token_encrypted);
        $phone = Crypt::decryptString((string) $invitation->contact_phone_encrypted);
        $baseUrl = rtrim((string) config('community_funeral.customer_app_url', 'http://localhost:50416'), '/');
        $routePrefix = (bool) config('community_funeral.customer_app_hash_routes', true) ? '/#' : '';
        $link = $baseUrl.$routePrefix.'/join-funeral?token='.rawurlencode($token);
        $amount = (string) $context->currency_code.' '.number_format(((int) $context->amount_minor) / 100, 2, '.', '');
        $message = sprintf(
            '%s / %s: %s. Contribution requested: %s. Join OleKard or sign in to verify this household request: %s. If this was not agreed, ignore this message.',
            (string) $organisationName,
            (string) $context->community_name,
            (string) $context->purpose,
            $amount,
            $link,
        );

        $db->table('community_services.funeral_household_invitations')->where('id', $this->invitationId)
            ->increment('attempt_count', 1, ['status' => 'sending', 'updated_at' => now()]);
        $result = $sms->send($phone, $message);
        if (! $result->success) {
            $db->table('community_services.funeral_household_invitations')->where('id', $this->invitationId)
                ->update(['status' => 'pending', 'updated_at' => now()]);
            throw new \RuntimeException('Funeral household invitation SMS delivery failed.');
        }

        $db->table('community_services.funeral_household_invitations')->where('id', $this->invitationId)->update([
            'status' => 'sent', 'provider_reference' => $result->providerRef, 'sent_at' => now(), 'updated_at' => now(),
        ]);
        $db->table('community_services.funeral_audit_events')->insert([
            'id' => (string) \Ramsey\Uuid\Uuid::uuid7(), 'action' => 'household.invitation.sent',
            'actor_id' => null, 'resource_type' => 'household_invitation', 'resource_id' => $this->invitationId,
            'from_status' => 'pending', 'to_status' => 'sent', 'occurred_at' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        DB::connection('community_services')->table('community_services.funeral_household_invitations')
            ->where('id', $this->invitationId)->whereNotIn('status', ['claimed', 'sent'])
            ->update(['status' => 'failed', 'updated_at' => now()]);
        Log::warning('community_funeral.household_invitation.failed', ['invitation_id' => $this->invitationId]);
    }
}
