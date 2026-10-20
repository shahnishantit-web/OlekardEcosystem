<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Http\Requests;

use App\Modules\Integration\Domain\Enums\IntegrationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateIntegrationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(IntegrationStatus::class)],
        ];
    }
}
