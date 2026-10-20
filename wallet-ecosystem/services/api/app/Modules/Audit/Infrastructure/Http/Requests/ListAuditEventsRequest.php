<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Requests;

use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListAuditEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category'       => ['sometimes', Rule::enum(AuditEventCategory::class)],
            'actor_id'       => ['sometimes', 'uuid'],
            'entity_type'    => ['sometimes', 'string', 'max:100'],
            'entity_id'      => ['sometimes', 'uuid'],
            'outcome'        => ['sometimes', Rule::enum(AuditOutcome::class)],
            'correlation_id' => ['sometimes', 'uuid'],
            'from'           => ['sometimes', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.u\Z'],
            'to'             => ['sometimes', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s.uP,Y-m-d\TH:i:s.u\Z'],
            'cursor'         => ['sometimes', 'string'],
            'per_page'       => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', config('audit.per_page', 20));
    }
}
