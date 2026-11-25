<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RecordOfflineContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'household_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'received_at' => ['nullable', 'date'],
        ];
    }
}
