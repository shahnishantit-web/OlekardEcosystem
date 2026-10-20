<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use App\Modules\Identity\Domain\Rules\ConfiguredPassword;
use Illuminate\Foundation\Http\FormRequest;

final class ResetAdminPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', new ConfiguredPassword],
            'password_confirmation' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.size' => 'Invalid reset token format.',
            'password.min' => 'Password must be at least 10 characters.',
            'password.regex' => 'Password must contain at least one uppercase letter, one number, and one special character.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }
}
