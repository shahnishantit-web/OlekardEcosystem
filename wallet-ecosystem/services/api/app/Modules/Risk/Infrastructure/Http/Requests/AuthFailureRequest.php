<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AuthFailureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'in:customer,agent'],
            'subject_id'   => ['required', 'uuid'],
            'device_id'    => ['required', 'string', 'max:128'],
        ];
    }
}
