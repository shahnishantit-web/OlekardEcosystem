<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'mobile_number' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'email'         => ['nullable', 'string', 'email:rfc,dns', 'max:255'],
            'actor_type'    => ['required', 'string', 'in:CUSTOMER,AGENT'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v): void {
            if (empty($this->input('mobile_number')) && empty($this->input('email'))) {
                $v->errors()->add('identifier', 'Either mobile_number or email is required.');
            }
        });
    }
}
