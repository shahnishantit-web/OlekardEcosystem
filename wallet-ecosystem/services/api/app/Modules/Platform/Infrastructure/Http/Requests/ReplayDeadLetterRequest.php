<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReplayDeadLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
