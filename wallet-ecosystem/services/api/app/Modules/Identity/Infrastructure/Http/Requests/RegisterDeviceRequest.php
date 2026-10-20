<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'device_name'     => ['required', 'string', 'max:100'],
            'platform'        => ['required', 'string', 'in:ios,android,web'],
            'fingerprint_hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }
}
