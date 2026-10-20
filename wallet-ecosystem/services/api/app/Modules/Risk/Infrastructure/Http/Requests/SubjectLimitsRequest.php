<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubjectLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id'               => ['required', 'uuid'],
            'per_transaction_limit'    => ['nullable', 'numeric', 'min:0.01'],
            'daily_limit'              => ['nullable', 'numeric', 'min:0.01'],
            'monthly_limit'            => ['nullable', 'numeric', 'min:0.01'],
            'action'                   => ['required', 'in:block,review,flag'],
            'effective_from'           => ['nullable', 'date'],
            'effective_until'          => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
