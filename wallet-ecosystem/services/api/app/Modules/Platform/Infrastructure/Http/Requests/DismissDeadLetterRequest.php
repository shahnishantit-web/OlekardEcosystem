<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DismissDeadLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
