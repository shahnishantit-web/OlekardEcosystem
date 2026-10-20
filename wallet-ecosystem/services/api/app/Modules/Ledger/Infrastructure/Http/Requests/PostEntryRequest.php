<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PostEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency_code'                        => ['required', 'string', 'max:10'],
            'correlation_id'                       => ['required', 'string'],
            'transaction_reference'                => ['nullable', 'string', 'max:255'],
            'description'                          => ['nullable', 'string'],
            'lines'                                => ['required', 'array', 'min:2'],
            'lines.*.ledger_account_reference'     => ['required', 'string'],
            'lines.*.direction'                    => ['required', 'string', 'in:debit,credit'],
            'lines.*.amount'                       => ['required', 'integer', 'min:1'],
            'lines.*.currency_code'                => ['nullable', 'string', 'max:10'],
        ];
    }
}
