<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:eligible,exempt'],
            'exemption_reference' => ['nullable', 'string', 'max:200'],
        ];
    }
}
