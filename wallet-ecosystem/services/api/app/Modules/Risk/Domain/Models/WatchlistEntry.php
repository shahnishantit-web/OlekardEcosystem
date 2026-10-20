<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WatchlistEntry extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.watchlist_entries';

    protected $guarded = [];

    protected $casts = [
        'aliases'    => 'array',
        'id_numbers' => 'array',
        'is_active'  => 'boolean',
        'listed_at'  => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function setFullNameAttribute(string $value): void
    {
        $this->attributes['full_name'] = $value;
        $this->attributes['normalized_name'] = $this->normalizeName($value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    private function normalizeName(string $name): string
    {
        $normalized = mb_strtolower($name);
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;

        return trim((string) preg_replace('/\s+/', ' ', $normalized));
    }
}
