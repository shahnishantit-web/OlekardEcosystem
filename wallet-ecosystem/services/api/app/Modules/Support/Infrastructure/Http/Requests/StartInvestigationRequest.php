<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartInvestigationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'investigator_id' => ['required', 'uuid'],
        ];
    }
}
