<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use App\Modules\Identity\Domain\Rules\ConfiguredPassword;
use Illuminate\Foundation\Http\FormRequest;

final class ChangeAdminPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', new ConfiguredPassword, 'different:current_password'],
            'new_password_confirmation' => ['required', 'string', 'same:new_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'new_password.min' => 'New password must be at least 10 characters.',
            'new_password.regex' => 'New password must contain uppercase letters, numbers, and special characters.',
            'new_password.different' => 'New password must differ from current password.',
            'new_password_confirmation.same' => 'Password confirmation does not match.',
        ];
    }
}
