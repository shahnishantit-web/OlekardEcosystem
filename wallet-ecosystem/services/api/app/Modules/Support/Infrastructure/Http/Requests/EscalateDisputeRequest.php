<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EscalateDisputeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'escalation_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
