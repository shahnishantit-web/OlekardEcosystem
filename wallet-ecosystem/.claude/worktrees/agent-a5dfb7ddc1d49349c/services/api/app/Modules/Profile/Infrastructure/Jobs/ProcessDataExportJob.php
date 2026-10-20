<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Jobs;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Profile\Domain\Contracts\ProfilePhotoStorageContract;
use App\Modules\Profile\Domain\Events\DataExportReadyEvent;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentDataExportRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessDataExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $exportId,
        public readonly string $userId,
        public readonly string $correlationId,
    ) {}

    public function handle(
        EloquentUserProfileRepository          $profiles,
        EloquentAccountStatusHistoryRepository $history,
        EloquentDataExportRepository           $exports,
        ProfilePhotoStorageContract            $storage,
        EventPublisherInterface                $events,
        ConfigurationServiceInterface          $config,
    ): void {
        $profile = $profiles->findByUserId($this->userId);
        if ($profile === null) {
            $exports->update($this->exportId, ['status' => 'failed', 'completed_at' => now()]);
            return;
        }

        try {
            $statusHistory = $history->getHistoryForUser($this->userId)
                ->map(fn ($h) => [
                    'from_status' => $h->from_status,
                    'to_status'   => $h->to_status,
                    'reason'      => $h->reason,
                    'actor_type'  => $h->actor_type,
                    'created_at'  => $h->created_at?->toISOString(),
                ])
                ->values()
                ->toArray();

            // Compile export payload — credentials, tokens, OTP values, raw IPs, profile_photo_key EXCLUDED
            // email and mobile_number are contact information, NOT credentials — required by FR-E02-040
            $exportData = [
                'user_id'             => $profile->user_id,
                'email'               => $profile->email,
                'mobile_number'       => $profile->mobile_number,
                'first_name'          => $profile->first_name,
                'last_name'           => $profile->last_name,
                'date_of_birth'       => $profile->date_of_birth?->toDateString(),
                'gender'              => $profile->gender,
                'address_street'      => $profile->address_street,
                'address_city'        => $profile->address_city,
                'address_region'      => $profile->address_region,
                'address_postal_code' => $profile->address_postal_code,
                'address_country'     => $profile->address_country,
                'preferred_language'  => $profile->preferred_language,
                'account_status'      => $profile->account_status,
                'account_status_history' => $statusHistory,
                // profile_change_summary: personal-info and contact-change audit history deferred
                // pending product decision on cross-module platform.audit_events query (T246)
                'exported_at'         => now()->toISOString(),
            ];

            $content    = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $storageKey = $storage->storeExport($this->userId, $content);

            $ttl         = (int) ($config->get('profile.photo.signed_url_ttl_seconds') ?? 86400);
            $downloadUrl = $storage->generateSignedUrl($storageKey, $ttl);
            $expiresAt   = now()->addSeconds($ttl);

            $exports->update($this->exportId, [
                'status'       => 'ready',
                'download_key' => $storageKey,
                'expires_at'   => $expiresAt,
                'completed_at' => now(),
            ]);

            $events->publish(new DataExportReadyEvent(
                userId:        $this->userId,
                correlationId: $this->correlationId,
                exportId:      $this->exportId,
                downloadUrl:   $downloadUrl,
            ));

        } catch (\Throwable $e) {
            $exports->update($this->exportId, ['status' => 'failed', 'completed_at' => now()]);
            throw $e;
        }
    }
}
