<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response for POST /v1/auth/otp/verify — registration flow.
 * Returns setup_token for the next step (SET_PIN).
 * Full authentication token response is issued by TokenService after PIN is set.
 * OTP value MUST NOT appear anywhere in this resource (BR-002).
 */
final class OtpVerifyResponseResource extends JsonResource
{
    public function __construct(
        private OtpChallenge $challenge,
        private string $setupToken,
    ) {}

    public function toArray($request): array
    {
        return [
            'success' => true,
            'data'    => [
                'user_id'    => $this->challenge->user_id,
                'setup_token' => $this->setupToken,
                'next_step'  => 'SET_PIN',
            ],
        ];
    }
}
