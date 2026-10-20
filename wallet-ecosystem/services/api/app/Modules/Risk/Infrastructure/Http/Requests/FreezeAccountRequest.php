<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FreezeAccountRequest extends FormRequest
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
            'reason'       => ['required', 'string', 'min:10', 'max:2000'],
            'checker_id'   => ['required', 'uuid'],
            'wallet_id'    => ['nullable', 'uuid'],
            'alert_id'     => ['nullable', 'uuid'],
            'decision_id'  => ['nullable', 'uuid'],
            'expires_at'   => ['nullable', 'date', 'after:now'],
        ];
    }
}
