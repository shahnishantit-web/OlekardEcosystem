<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Requests;

final class PaginationRequest extends AdminAppRequest
{
    private int $maxPerPage;

    public function __construct(int $maxPerPage = 100)
    {
        parent::__construct();
        $this->maxPerPage = $maxPerPage;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', "max:{$this->maxPerPage}"],
        ];
    }

    public function page(): int
    {
        return (int) $this->input('page', 1);
    }

    public function perPage(int $default = 25): int
    {
        return (int) $this->input('per_page', $default);
    }
}
