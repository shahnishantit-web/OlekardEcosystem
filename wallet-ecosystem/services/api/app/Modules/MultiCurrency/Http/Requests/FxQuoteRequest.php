<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FxQuoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'from_currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'to_currency_code'   => ['required', 'string', 'size:3', 'alpha'],
            'from_amount'        => ['required', 'numeric', 'gt:0'],
        ];
    }
}
