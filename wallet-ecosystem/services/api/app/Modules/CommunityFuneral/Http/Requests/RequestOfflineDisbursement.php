<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestOfflineDisbursement extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'recipient_actor_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
