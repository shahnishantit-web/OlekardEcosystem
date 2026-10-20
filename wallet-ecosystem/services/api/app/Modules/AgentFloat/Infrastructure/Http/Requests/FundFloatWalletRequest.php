<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FundFloatWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount'        => ['required', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'max:3'],
        ];
    }
}
