<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T041: Feature tests for admin dispute case management endpoints.
 *
 * @group docker
 */
final class AdminDisputeCaseControllerTest extends TestCase
{
    use RefreshDatabase;

    /** (a) GET /dispute-cases with finance_review filter returns only matching cases */
    public function test_index_filters_by_requires_finance_review(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (b) GET /dispute-cases/{id} returns full case detail with events and hold */
    public function test_show_returns_case_detail_with_events(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (c) POST /dispute-cases/{id}/hold creates hold and transitions to under_review */
    public function test_hold_authorises_hold_and_transitions_status(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (d) POST /dispute-cases/{id}/hold with zero amount and exposure_type=hold returns 422 */
    public function test_hold_with_zero_amount_for_hold_type_returns_422(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (e) POST /dispute-cases/{id}/outcome won path releases hold */
    public function test_outcome_won_releases_hold(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (f) POST /dispute-cases/{id}/outcome lost path posts correcting journal */
    public function test_outcome_lost_posts_correcting_journal(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (g) POST /dispute-cases/{id}/outcome contradictory (won after lost) → investigation_required */
    public function test_contradictory_outcome_transitions_to_investigation_required(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (h) POST /dispute-cases/{id}/evidence appends event to timeline */
    public function test_submit_evidence_appends_event(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (i) POST /dispute-cases/{id}/finance-review clears requires_finance_review flag */
    public function test_finance_review_acknowledged_clears_flag(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }

    /** (j) Missing payment.disputes.manage permission → 403 on hold endpoint */
    public function test_missing_manage_permission_returns_403(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded dispute cases).');
    }
}
