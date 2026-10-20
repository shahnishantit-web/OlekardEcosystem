<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResendOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'uuid'],
        ];
    }
}
