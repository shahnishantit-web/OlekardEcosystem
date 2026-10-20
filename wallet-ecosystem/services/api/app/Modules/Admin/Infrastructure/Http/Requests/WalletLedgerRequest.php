<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WalletLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'       => ['sometimes', 'date'],
            'to'         => ['sometimes', 'date', 'after_or_equal:from'],
            'entry_type' => ['sometimes', 'string', 'max:50'],
            'currency'   => ['sometimes', 'string', 'in:BWP'],
            'per_page'   => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'       => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
