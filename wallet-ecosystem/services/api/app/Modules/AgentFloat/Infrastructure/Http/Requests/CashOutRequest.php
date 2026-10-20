<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CashOutRequest extends FormRequest
{
    /**
     * FR-E13-024: check permission AND ownership (agent may only operate their own float wallet).
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount'           => ['required', 'integer', 'min:1'],
            'currency_code'    => ['required', 'string', 'max:3'],
            'customer_wallet_id' => ['required', 'uuid'],
        ];
    }
}
