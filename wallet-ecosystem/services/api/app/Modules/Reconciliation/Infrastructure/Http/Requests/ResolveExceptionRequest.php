<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolveExceptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'resolution_type' => [
                'required',
                Rule::in(['manually_matched', 'written_off', 'reversed', 'duplicate_confirmed']),
            ],
            'resolution' => ['required', 'string', 'max:2000'],
        ];
    }
}
