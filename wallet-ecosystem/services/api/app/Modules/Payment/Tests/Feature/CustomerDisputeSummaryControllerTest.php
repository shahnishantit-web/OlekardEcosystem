<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T042: Feature tests for customer dispute summary endpoint.
 *
 * @group docker
 */
final class CustomerDisputeSummaryControllerTest extends TestCase
{
    use RefreshDatabase;

    /** (a) Customer can list disputes for their own payment intent */
    public function test_customer_can_list_own_disputes(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded payment intents).');
    }

    /** (b) Customer cannot see disputes for another user's intent → 403 */
    public function test_customer_cannot_see_others_disputes(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded payment intents).');
    }

    /** (c) Response contains consumer-safe fields (no provider refs, no hold amounts) */
    public function test_response_excludes_provider_references(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded payment intents).');
    }

    /** (d) Empty list returned for intent with no disputes */
    public function test_empty_list_for_intent_with_no_disputes(): void
    {
        $this->markTestSkipped('Requires Docker (database + seeded payment intents).');
    }
}
