<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateCurrencyWalletRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'currency_code' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
        ];
    }
}
