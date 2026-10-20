<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveCommissionPayoutRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'agent_id'          => ['required', 'string', 'uuid'],
            'commission_ids'    => ['required', 'array', 'min:1'],
            'commission_ids.*'  => ['required', 'string', 'uuid'],
        ];
    }
}
