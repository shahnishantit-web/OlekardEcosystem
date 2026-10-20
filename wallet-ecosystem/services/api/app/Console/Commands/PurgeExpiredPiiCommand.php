<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purges PII records that have exceeded their configured retention period.
 * Records are only purged when pii_retention_days > 0 (value of 0 means disabled/no purge).
 *
 * T076 — resolves C3 (FR-014 PII retention gap).
 */
final class PurgeExpiredPiiCommand extends Command
{
    protected $signature   = 'app:purge-expired-pii';
    protected $description = 'Purge or anonymize PII records older than their configured retention period.';

    private const BATCH_SIZE = 250;

    private const CATEGORIES = [
        'identity'    => 'pii.retention_days.identity',
        'kyc'         => 'pii.retention_days.kyc',
        'transaction' => 'pii.retention_days.transaction',
    ];

    public function handle(): int
    {
        $totalPurged = 0;

        foreach (self::CATEGORIES as $category => $configKey) {
            $retentionDays = (int) config($configKey, 0);

            if ($retentionDays <= 0) {
                $this->line("Category [{$category}]: retention disabled (days=0), skipping.");
                continue;
            }

            $cutoff  = now()->subDays($retentionDays);
            $purged  = $this->purgeCategory($category, $cutoff);
            $totalPurged += $purged;

            $this->info("Category [{$category}]: purged {$purged} records older than {$cutoff->toDateString()} (retention={$retentionDays}d).");

            Log::info('pii.purge.category', [
                'category'       => $category,
                'retention_days' => $retentionDays,
                'cutoff'         => $cutoff->toIso8601String(),
                'purged'         => $purged,
            ]);
        }

        $this->info("PII purge complete. Total records purged: {$totalPurged}.");

        return self::SUCCESS;
    }

    private function purgeCategory(string $category, \Carbon\CarbonInterface $cutoff): int
    {
        return match ($category) {
            'identity'    => $this->purgeIdentityPii($cutoff),
            'kyc'         => $this->purgeKycPii($cutoff),
            'transaction' => $this->purgeTransactionPii($cutoff),
            default       => 0,
        };
    }

    private function purgeIdentityPii(\Carbon\CarbonInterface $cutoff): int
    {
        $purged = 0;

        // Anonymize soft-deleted or inactive identity records older than cutoff
        DB::connection('identity')
            ->table('identity.users')
            ->where('status', 'closed')
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($rows) use (&$purged): void {
                foreach ($rows as $row) {
                    DB::connection('identity')
                        ->table('identity.users')
                        ->where('id', $row->id)
                        ->update([
                            'email'        => 'purged-' . $row->id . '@purged.invalid',
                            'phone_number' => null,
                            'display_name' => 'Purged User',
                            'updated_at'   => now(),
                        ]);
                    $purged++;
                }
            });

        return $purged;
    }

    private function purgeKycPii(\Carbon\CarbonInterface $cutoff): int
    {
        $purged = 0;

        // Delete KYC personal info for rejected/withdrawn applications older than cutoff
        DB::connection('kyc')
            ->table('kyc.kyc_personal_info')
            ->whereIn('case_id', static function ($query) use ($cutoff): void {
                $query->select('id')
                    ->from('kyc.kyc_cases')
                    ->whereIn('status', ['rejected', 'withdrawn'])
                    ->where('updated_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(self::BATCH_SIZE, function ($rows) use (&$purged): void {
                $ids = collect($rows)->pluck('id')->all();
                DB::connection('kyc')->table('kyc.kyc_personal_info')->whereIn('id', $ids)->delete();
                $purged += count($ids);
            });

        return $purged;
    }

    private function purgeTransactionPii(\Carbon\CarbonInterface $cutoff): int
    {
        // Transaction PII purge is a no-op by default: transaction records are
        // financial ledger entries subject to regulatory retention. This placeholder
        // exists so the config key can be activated when legal confirms the retention period.
        $this->line('Category [transaction]: purge is a no-op (requires legal sign-off on retention window).');
        return 0;
    }
}
