<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FxQuoteConfirmRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255'],
        ];
    }
}
