<?php

declare(strict_types=1);

namespace App\Modules\Qa\Http\Requests;

use App\Modules\Qa\Domain\Enums\QaCategory;
use Illuminate\Foundation\Http\FormRequest;

class TriggerQaRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by route middleware
    }

    public function rules(): array
    {
        return [];
    }

    public function getIdempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');
        if (empty($key)) {
            abort(422, 'QA_IDEM_REQUIRED');
        }
        return $key;
    }

    public function getQaCategory(): QaCategory
    {
        return QaCategory::fromSlug($this->route('category'));
    }

    public function getCorrelationId(): string
    {
        return $this->header('X-Correlation-Id') ?? (string) \Ramsey\Uuid\Uuid::uuid7();
    }
}
