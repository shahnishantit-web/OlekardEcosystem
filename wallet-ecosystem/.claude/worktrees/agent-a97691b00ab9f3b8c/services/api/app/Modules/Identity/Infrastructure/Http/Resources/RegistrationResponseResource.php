<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response for POST /v1/auth/register.
 * OTP value MUST NOT appear in any field of this resource (BR-002; AC-020).
 */
final class RegistrationResponseResource extends JsonResource
{
    public function __construct(private OtpChallenge $challenge) {}

    public function toArray($request): array
    {
        $identifier       = $this->challenge->identifier;
        $maskedIdentifier = str_contains($identifier, '@')
            ? PiiMasker::maskEmail($identifier)
            : PiiMasker::maskPhone($identifier);

        $resendCooldown = $this->challenge->last_resent_at
            ? \Carbon\Carbon::parse($this->challenge->last_resent_at)->addSeconds(60)
            : null;

        return [
            'success' => true,
            'data'    => [
                'challenge_id'       => $this->challenge->id,
                'expires_at'         => $this->challenge->expires_at,
                'resend_at'          => $resendCooldown,
                'masked_destination' => $maskedIdentifier,
            ],
        ];
    }
}
