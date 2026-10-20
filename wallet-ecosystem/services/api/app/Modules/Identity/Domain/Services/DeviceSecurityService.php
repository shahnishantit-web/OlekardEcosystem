<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Services;

use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceSecurityEventRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceTrustHistoryRepositoryInterface;
use App\Modules\Identity\Domain\Events\DeviceChangeDetectedEvent;
use App\Modules\Identity\Domain\Events\DeviceSuspiciousDetectedEvent;
use App\Modules\Identity\Domain\Exceptions\AcknowledgmentWindowExpiredException;
use App\Modules\Identity\Domain\Exceptions\DeviceAlreadyAcknowledgedException;
use App\Modules\Identity\Infrastructure\Models\Device;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class DeviceSecurityService
{
    public function __construct(
        private readonly DeviceRepositoryInterface             $devices,
        private readonly DeviceSecurityEventRepositoryInterface $securityEvents,
        private readonly DeviceTrustHistoryRepositoryInterface  $trustHistory,
        private readonly DeviceTrustService                    $trustService,
        private readonly EventPublisherInterface               $events,
        private readonly AuditServiceInterface                 $audit,
        private readonly ConfigurationServiceInterface         $config,
        private readonly IdentityMetrics                       $metrics,
    ) {}

    /**
     * Detect a fingerprint change on a known device.
     * Returns true if a change was detected and the device was demoted.
     */
    public function detectChange(
        Device $device,
        string $submittedHash,
        string $userId,
        string $actorType,
        string $correlationId,
        string $ipHash,
    ): bool {
        $changeEnabled = $this->config->getOrDefault('device.change_detection_enabled', 'true');

        if ($changeEnabled !== 'true') {
            return false;
        }

        if ($device->device_fingerprint === $submittedHash) {
            return false;
        }

        $previousHash = $device->device_fingerprint;
        $fromStatus   = $device->status;
        $deviceId     = $device->id;

        DB::connection('platform')->transaction(function () use ($deviceId, $userId, $previousHash, $submittedHash, $fromStatus, $actorType, $correlationId, $ipHash): void {
            $this->devices->updateStatus($deviceId, 'pending_verification');

            $this->trustHistory->append([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'from_status'    => $fromStatus,
                'to_status'      => 'pending_verification',
                'actor_id'       => $userId,
                'actor_type'     => $actorType,
                'reason'         => 'fingerprint_change_detected',
                'correlation_id' => $correlationId,
                'created_at'     => now(),
            ]);

            $this->securityEvents->create([
                'id'                        => (string) Uuid::uuid7(),
                'device_id'                 => $deviceId,
                'user_id'                   => $userId,
                'event_type'                => 'change_detected',
                'fingerprint_hash'          => $submittedHash,
                'previous_fingerprint_hash' => $previousHash,
                'correlation_id'            => $correlationId,
            ]);

            $this->events->publish(new DeviceChangeDetectedEvent(
                deviceId:             $deviceId,
                actorId:              $userId,
                correlationId:        $correlationId,
                userId:               $userId,
                previousFingerprintHash: $previousHash,
                newFingerprintHash:   $submittedHash,
                previousStatus:       $fromStatus,
                newStatus:            'pending_verification',
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.change-detected',
                actorType:     $actorType,
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $deviceId,
                beforeState:   ['status' => $fromStatus],
                afterState:    ['status' => 'pending_verification'],
                ipAddress:     $ipHash,
            ));
        });

        $this->metrics->incrementDeviceChangeDetected();

        return true;
    }

    /**
     * Evaluate suspicious detection rules for a device.
     * Rule 1: fingerprint shared by > max_accounts_per_fingerprint users
     * Rule 2: emulator flag
     * Rule 3: abnormal change rate in window
     */
    public function detectSuspicious(
        Device $device,
        string $userId,
        bool   $isEmulator,
        string $correlationId,
    ): void {
        $suspiciousEnabled = $this->config->getOrDefault('device.suspicious_detection_enabled', 'true');

        if ($suspiciousEnabled !== 'true') {
            return;
        }

        $rulesFired = [];

        // Rule 1: multi-account fingerprint
        $maxAccounts = (int) $this->config->getOrDefault('device.max_accounts_per_fingerprint', '3');
        $devicesWithFingerprint = $this->devices->findByFingerprint($device->device_fingerprint ?? '');
        $distinctUsers = array_unique(array_map(static fn ($d) => (string) $d->user_id, $devicesWithFingerprint));

        if (count($distinctUsers) > $maxAccounts) {
            $rulesFired[] = 'multi_account_fingerprint';
        }

        // Rule 2: emulator indicator
        if ($isEmulator) {
            $rulesFired[] = 'emulator_detected';
        }

        // Rule 3: abnormal change rate
        $windowHours  = (int) $this->config->getOrDefault('device.suspicious_change_window_hours', '24');
        $maxChanges   = (int) $this->config->getOrDefault('device.max_device_changes_per_window', '3');
        $changeCount  = DB::connection('identity')
            ->table('identity.device_security_events')
            ->where('user_id', $userId)
            ->where('event_type', 'change_detected')
            ->where('created_at', '>', now()->subHours($windowHours))
            ->count();

        if ($changeCount > $maxChanges) {
            $rulesFired[] = 'abnormal_change_rate';
        }

        if (empty($rulesFired)) {
            return;
        }

        $riskSignals = ['rules_fired' => $rulesFired];
        $deviceId    = $device->id;

        DB::connection('platform')->transaction(function () use ($deviceId, $userId, $riskSignals, $correlationId, $rulesFired): void {
            $this->securityEvents->create([
                'id'             => (string) Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'event_type'     => 'suspicious_detected',
                'risk_signals'   => $riskSignals,
                'correlation_id' => $correlationId,
            ]);

            $this->events->publish(new DeviceSuspiciousDetectedEvent(
                deviceId:      $deviceId,
                actorId:       null,
                correlationId: $correlationId,
                userId:        $userId,
                riskSignals:   $riskSignals,
            ));

            $this->audit->record(new AuditEventData(
                action:        'identity.device.suspicious-detected',
                actorType:     'system',
                resourceType:  'identity.device',
                correlationId: $correlationId,
                outcome:       'success',
                resourceId:    $deviceId,
                afterState:    ['risk_signals' => ['rules_fired' => $rulesFired]],
            ));
        });

        foreach ($rulesFired as $rule) {
            $this->metrics->incrementDeviceSuspiciousDetected($rule);
        }
    }

    /**
     * Process a new-device acknowledgment.
     * Returns count of sessions revoked (> 0 only when acknowledged = false).
     */
    public function processAcknowledgment(
        Device $device,
        bool   $acknowledged,
        string $userId,
        string $actorType,
        string $correlationId,
        string $ipHash,
    ): int {
        // Use findLatestNewDeviceEvent to distinguish "already resolved" from "no event / expired".
        $securityEvent = $this->securityEvents->findLatestNewDeviceEvent($device->id);

        if ($securityEvent === null) {
            throw AcknowledgmentWindowExpiredException::make();
        }

        if ($securityEvent->resolution !== null) {
            throw DeviceAlreadyAcknowledgedException::make();
        }

        if ($securityEvent->acknowledgment_expires_at !== null && now()->greaterThan($securityEvent->acknowledgment_expires_at)) {
            throw AcknowledgmentWindowExpiredException::make();
        }

        $sessionsRevoked = 0;

        if (! $acknowledged) {
            $sessionsRevoked = $this->trustService->blockDevice(
                deviceId:      $device->id,
                userId:        $userId,
                reason:        'new_device_denied_by_user',
                isAdmin:       false,
                adminActorId:  null,
                actorType:     $actorType,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            );

            $this->securityEvents->markResolved(
                $securityEvent->id,
                'blocked',
                now()->toIso8601String(),
            );
        } else {
            $this->securityEvents->markResolved(
                $securityEvent->id,
                'acknowledged',
                now()->toIso8601String(),
            );
        }

        return $sessionsRevoked;
    }
}
