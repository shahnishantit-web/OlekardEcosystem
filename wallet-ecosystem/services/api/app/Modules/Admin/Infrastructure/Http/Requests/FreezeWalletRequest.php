<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FreezeWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'     => ['required', 'string', 'min:10', 'max:2000'],
            'checker_id' => ['required', 'uuid'],
            'hold_type'  => ['required', 'string', 'in:risk_freeze,compliance_hold,aml_investigation'],
        ];
    }
}
