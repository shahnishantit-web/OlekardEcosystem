<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateFloatWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency_code' => ['required', 'string', 'max:10'],
        ];
    }
}
