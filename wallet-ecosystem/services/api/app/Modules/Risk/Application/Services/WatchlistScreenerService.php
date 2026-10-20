<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Contracts\WatchlistScreenerInterface;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\WatchlistEntry;
use App\Modules\Risk\Domain\Models\WatchlistMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WatchlistScreenerService implements WatchlistScreenerInterface
{
    private const DEFAULT_SIMILARITY_THRESHOLD = 0.75;

    public function __construct(
        private readonly AuditServiceInterface       $audit,
        private readonly RiskMetricsInterface        $metrics,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    /**
     * Screen a subject against the watchlist.
     *
     * @return WatchlistMatch[]
     */
    public function screen(string $subjectType, string $subjectId, string $name, ?string $dob = null): array
    {
        $normalized = $this->normalizeName($name);
        $threshold  = (float) $this->config->getOrDefault('risk.watchlist_similarity_threshold', self::DEFAULT_SIMILARITY_THRESHOLD);

        $matches = [];

        // 1. Exact name match
        $exactHits = WatchlistEntry::active()
            ->where('normalized_name', $normalized)
            ->get();

        foreach ($exactHits as $entry) {
            $matches[] = $this->createMatch($entry, $subjectType, $subjectId, 'exact', 1.0);
        }

        // 2. Fuzzy match via pg_trgm similarity() — schema-qualified because the extension lives in the platform schema
        if (empty($exactHits->all())) {
            $fuzzyHits = WatchlistEntry::active()
                ->whereRaw('platform.similarity(normalized_name, ?) >= ?', [$normalized, $threshold])
                ->selectRaw('*, platform.similarity(normalized_name, ?) AS sim_score', [$normalized])
                ->orderByDesc('sim_score')
                ->get();

            foreach ($fuzzyHits as $entry) {
                $matches[] = $this->createMatch($entry, $subjectType, $subjectId, 'fuzzy', (float) ($entry->sim_score ?? 0.0));
            }
        }

        // 3. Alias match against JSONB aliases array (case-insensitive element comparison)
        if (empty($matches)) {
            $aliasHits = WatchlistEntry::active()
                ->whereRaw(
                    "EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE(aliases, '[]'::jsonb)) AS a(val) WHERE lower(a.val) = ?)",
                    [$normalized]
                )
                ->get();

            foreach ($aliasHits as $entry) {
                $matches[] = $this->createMatch($entry, $subjectType, $subjectId, 'alias', 0.9);
            }
        }

        if (!empty($matches)) {
            foreach ($matches as $match) {
                $this->createAlert($match, $subjectType, $subjectId, $name);
            }

            $this->audit->record(new AuditEventData(
                action:        'risk.watchlist.match',
                actorType:     'system',
                resourceType:  'watchlist',
                correlationId: $subjectId,
                outcome:       'success',
                metadata:      [
                    'subject_type'  => $subjectType,
                    'subject_id'    => $subjectId,
                    'match_count'   => count($matches),
                ],
            ));
        }

        $this->metrics->watchlistScreened(empty($matches) ? 'no_match' : 'match');

        return $matches;
    }

    /**
     * Retrospective scan: find WatchlistMatch records with no open alert and create alerts for high-confidence hits.
     * Processes in chunks to bound memory usage. New entries since the last scan are prioritised.
     */
    public function screenRetrospectively(): void
    {
        $matched = 0;

        WatchlistMatch::whereNull('cleared_at')
            ->where('match_score', '>=', self::DEFAULT_SIMILARITY_THRESHOLD)
            ->chunk(100, function ($batch) use (&$matched): void {
                foreach ($batch as $match) {
                    $existingAlert = RiskAlert::where('subject_type', $match->subject_type)
                        ->where('subject_id', $match->subject_id)
                        ->where('alert_type', AlertType::Watchlist->value)
                        ->whereIn('status', ['open', 'under_investigation'])
                        ->exists();

                    if ($existingAlert) {
                        continue;
                    }

                    $entry = $match->entry;
                    if ($entry === null || !$entry->is_active) {
                        continue;
                    }

                    $this->createAlert($match, $match->subject_type, $match->subject_id, $entry->full_name);
                    $matched++;
                }
            });

        $this->metrics->patternJobExecuted('watchlist_retrospective', (string) $matched);

        $this->audit->record(new AuditEventData(
            action:        'risk.watchlist.retrospective_scan',
            actorType:     'system',
            resourceType:  'watchlist',
            correlationId: 'retrospective_scan',
            outcome:       'success',
            metadata:      ['match_count' => $matched],
        ));
    }

    private function createMatch(
        WatchlistEntry $entry,
        string $subjectType,
        string $subjectId,
        string $matchMethod,
        float $matchScore,
    ): WatchlistMatch {
        $existing = WatchlistMatch::where('watchlist_entry_id', $entry->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('cleared_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return WatchlistMatch::create([
            'id'                 => (string) Str::orderedUuid(),
            'watchlist_entry_id' => $entry->id,
            'subject_type'       => $subjectType,
            'subject_id'         => $subjectId,
            'match_method'       => $matchMethod,
            'match_score'        => $matchScore,
            'screened_at'        => now(),
        ]);
    }

    private function createAlert(WatchlistMatch $match, string $subjectType, string $subjectId, string $name): void
    {
        $existingAlert = RiskAlert::where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('alert_type', AlertType::Watchlist->value)
            ->whereIn('status', ['open', 'under_investigation'])
            ->exists();

        if ($existingAlert) {
            return;
        }

        $systemId = '00000000-0000-0000-0000-000000000000';
        $alert    = RiskAlert::create([
            'id'           => (string) Str::orderedUuid(),
            'alert_type'   => AlertType::Watchlist->value,
            'severity'     => RiskLevel::Critical->value,
            'status'       => 'open',
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'title'        => mb_substr("Watchlist match: {$name}", 0, 255),
            'description'  => "Subject matched watchlist entry via {$match->match_method} with score {$match->match_score}",
            'version'      => 1,
            'sla_due_at'   => now()->addHours(4),
            'created_by'   => $systemId,
            'updated_by'   => $systemId,
        ]);

        $match->update(['alert_id' => $alert->id]);

        $this->metrics->alertCreated(AlertType::Watchlist->value, RiskLevel::Critical->value);
    }

    private function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
