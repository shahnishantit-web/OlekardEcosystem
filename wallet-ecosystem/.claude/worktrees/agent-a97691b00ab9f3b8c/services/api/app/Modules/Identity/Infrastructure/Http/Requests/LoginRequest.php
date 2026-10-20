<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'credential_type'    => ['required', 'string', 'in:pin,password,otp'],
            'identifier'         => ['nullable', 'string', 'max:255'],
            'credential'         => ['nullable', 'string', 'max:255'],
            'challenge_id'       => ['nullable', 'uuid'],
            'otp'                => ['nullable', 'string', 'digits_between:4,8'],
            'device_fingerprint' => ['nullable', 'string', 'max:255'],
            'device_name'        => ['nullable', 'string', 'max:255'],
            'platform'           => ['nullable', 'string', 'in:ios,android,web,unknown'],
        ];
    }
}
