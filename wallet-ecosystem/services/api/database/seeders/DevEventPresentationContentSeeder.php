<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Adds transparent presentation copy to local event fixtures without changing workflow state. */
final class DevEventPresentationContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Event presentation content is local-demo only.');
        }

        $events = DB::connection('community_services');
        $rows = $events->table('community_services.event_drafts')->orderBy('title')->get();
        $updated = 0;

        foreach ($rows as $event) {
            $category = (string) ($event->event_category ?: 'other');
            $organiser = trim((string) ($event->organiser_name ?: 'the event organiser'));
            $title = trim((string) $event->title);
            $city = $this->cityFor($organiser, $title);
            $changes = [];

            $existingDescription = trim((string) ($event->description ?? ''));
            if ($existingDescription === ''
                || (str_contains(strtolower($title), 'paid ticket demo')
                    && str_contains($existingDescription, 'is a community event, hosted by'))) {
                $changes['description'] = $this->description($category, $organiser, $title);
            }
            if (trim((string) ($event->venue_name ?? '')) === '') {
                $changes['venue_name'] = 'Venue details to be confirmed';
            }
            if (trim((string) ($event->venue_city ?? '')) === '' && $city !== null) {
                $changes['venue_city'] = $city;
            }
            if (trim((string) ($event->venue_address ?? '')) === '') {
                $changes['venue_address'] = 'The organiser will share the confirmed venue address and directions before the event.';
            }

            if ($changes === []) {
                continue;
            }

            // Keep the public snapshot aligned without altering version, approvals, or sales state.
            if ($event->published_snapshot !== null) {
                $snapshot = json_decode((string) $event->published_snapshot, true, 512, JSON_THROW_ON_ERROR);
                foreach ($changes as $field => $value) {
                    if (empty($snapshot[$field])) {
                        $snapshot[$field] = $value;
                    }
                }
                $changes['published_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR);
            }

            $changes['updated_at'] = now();
            $events->table('community_services.event_drafts')->where('id', $event->id)->update($changes);
            $updated++;
        }

        $this->command?->info("Added missing presentation details to {$updated} local events. Venue addresses remain explicitly unconfirmed.");
    }

    private function description(string $category, string $organiser, string $title): string
    {
        if (str_contains(strtolower($title), 'paid ticket demo')) {
            return "{$title} is a local OleKard POC event for demonstrating paid ticket selection and checkout, hosted by {$organiser}. The event programme and exact venue details are demo placeholders and will be confirmed by the organiser.";
        }

        $purpose = match ($category) {
            'community' => 'a community gathering bringing local residents together',
            'music' => 'a music event for local audiences',
            'sports' => 'a community sports event',
            'club_game' => 'a club game for local supporters',
            'business' => 'a business and networking event',
            'arts' => 'a community arts and culture event',
            'family' => 'a family-focused community event',
            default => 'a community event',
        };

        return "{$title} is {$purpose}, hosted by {$organiser}. The organiser will confirm the programme and any event-specific arrangements. Please check the event details again before attending.";
    }

    private function cityFor(string $organiser, string $title): ?string
    {
        $source = strtolower($organiser.' '.$title);
        foreach (['Gaborone', 'Francistown', 'Maun', 'Kanye', 'Molepolole'] as $city) {
            if (str_contains($source, strtolower($city))) {
                return $city;
            }
        }

        return null;
    }
}
