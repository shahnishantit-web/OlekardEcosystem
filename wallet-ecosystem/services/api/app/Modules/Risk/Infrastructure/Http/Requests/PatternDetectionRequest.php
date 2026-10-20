<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PatternDetectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_type'  => ['required', 'string', 'in:customer,agent'],
            'subject_id'    => ['required', 'uuid'],
            'lookback_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}
