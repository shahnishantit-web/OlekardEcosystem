<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Http\Requests;

use App\Modules\Support\Domain\Enums\DisputeReasonCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SelectDisputeReasonRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reason'      => ['required', Rule::enum(DisputeReasonCode::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
