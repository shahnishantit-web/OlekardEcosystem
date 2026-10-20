<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description'    => ['nullable', 'string', 'max:500'],
            'correlation_id' => ['required', 'string'],
        ];
    }
}
