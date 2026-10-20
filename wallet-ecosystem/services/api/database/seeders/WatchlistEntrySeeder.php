<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Risk\Domain\Models\WatchlistEntry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WatchlistEntrySeeder extends Seeder
{
    public function run(): void
    {
        if (!in_array(app()->environment(), ['local', 'testing'], true)) {
            return;
        }

        $entries = [
            [
                'id'            => (string) Str::orderedUuid(),
                'full_name'     => 'John Doe Sanctions',
                'entry_type'    => 'sanctions',
                'source'        => 'UN Consolidated List',
                'date_of_birth' => '1975-04-12',
                'nationality'   => 'ZW',
                'id_numbers'    => ['PASSPORT-SD123456'],
                'aliases'       => ['J. Doe', 'Johnny Doe'],
                'is_active'     => true,
                'listed_at'     => now()->subYears(2),
            ],
            [
                'id'            => (string) Str::orderedUuid(),
                'full_name'     => 'Amelia Reinholt',
                'entry_type'    => 'pep',
                'source'        => 'Botswana PEP Registry',
                'date_of_birth' => '1968-11-30',
                'nationality'   => 'BW',
                'id_numbers'    => ['OMANG-987654321'],
                'aliases'       => [],
                'is_active'     => true,
                'listed_at'     => now()->subYear(),
            ],
            [
                'id'            => (string) Str::orderedUuid(),
                'full_name'     => 'Roberto Villanueva-Cruz',
                'entry_type'    => 'sanctions',
                'source'        => 'OFAC SDN List',
                'date_of_birth' => '1980-07-04',
                'nationality'   => 'MX',
                'id_numbers'    => ['RFC-VICR800704XXX', 'PASSPORT-G12345678'],
                'aliases'       => ['R. Villanueva', 'Roberto Cruz', 'Bob Cruz'],
                'is_active'     => true,
                'listed_at'     => now()->subMonths(8),
            ],
            [
                'id'            => (string) Str::orderedUuid(),
                'full_name'     => 'Mjohnson Phiri',
                'entry_type'    => 'internal_blocklist',
                'source'        => 'Internal Fraud Team',
                'date_of_birth' => null,
                'nationality'   => 'BW',
                'id_numbers'    => [],
                'aliases'       => ['M. Phiri', 'Mjonson Phiri', 'Mjohnson Phire'],
                'is_active'     => true,
                'listed_at'     => now()->subMonths(3),
            ],
            [
                'id'            => (string) Str::orderedUuid(),
                'full_name'     => 'Tebogo Mokoena',
                'entry_type'    => 'adverse_media',
                'source'        => 'Media Monitoring Service',
                'date_of_birth' => '1990-02-15',
                'nationality'   => 'ZA',
                'id_numbers'    => ['ZA-ID-9002155432089'],
                'aliases'       => ['T. Mokoena'],
                'is_active'     => true,
                'listed_at'     => now()->subWeeks(6),
            ],
        ];

        foreach ($entries as $data) {
            WatchlistEntry::firstOrCreate(['id' => $data['id']], $data);
        }
    }
}
