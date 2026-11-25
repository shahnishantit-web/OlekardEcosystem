<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestFuneralDisbursement extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('destination_type')) {
            $this->merge(['destination_type' => 'wallet']);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'destination_type' => ['required', 'string', 'in:wallet,bank_transfer'],
            'recipient_actor_id' => ['required', 'uuid'],
            'beneficiary_id' => ['required_if:destination_type,bank_transfer', 'nullable', 'uuid'],
        ];
    }
}
