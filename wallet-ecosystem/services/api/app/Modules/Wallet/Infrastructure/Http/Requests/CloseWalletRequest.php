<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CloseWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
