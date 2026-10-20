<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SuspendAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when suspending an account.',
            'reason.min'      => 'Reason must not be empty.',
        ];
    }
}
