<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ManualDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'evaluation_id' => ['required', 'uuid'],
            'rationale'     => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
