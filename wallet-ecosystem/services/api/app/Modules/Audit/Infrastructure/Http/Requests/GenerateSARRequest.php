<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateSARRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_user_id'          => ['required', 'uuid'],
            'reason_code'              => ['required', 'string', 'max:64'],
            'reason_narrative'         => ['required', 'string', 'max:5000'],
            'flagged_transaction_ids'  => ['nullable', 'array'],
            'flagged_transaction_ids.*' => ['uuid'],
        ];
    }
}
