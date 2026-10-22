<?php

namespace Tests\Unit;

use App\Enums\DismantlingOrderStatus as S;
use PHPUnit\Framework\TestCase;

class DismantlingOrderStatusTest extends TestCase
{
    public function test_no_shortcut_from_draft_to_work_or_completion(): void
    {
        $this->assertFalse(S::Draft->canTransitionTo(S::Approved));
        $this->assertFalse(S::Draft->canTransitionTo(S::InProgress));
        $this->assertFalse(S::PendingReview->canTransitionTo(S::Approved));
        $this->assertFalse(S::Approved->canTransitionTo(S::Completed));
        $this->assertTrue(S::PendingAgreement->canTransitionTo(S::Approved));
    }

    public function test_final_states_are_terminal(): void
    {
        foreach ([S::Completed, S::LeftInPlace, S::Cancelled] as $status) {
            $this->assertTrue($status->isTerminal());
            $this->assertFalse($status->isActive());
        }
    }

    public function test_every_order_status_maps_to_a_segment_status(): void
    {
        foreach (S::cases() as $status) {
            $this->assertNotNull($status->segmentStatus());
        }
        $this->assertSame('not_planned', S::Cancelled->segmentStatus()->value);
    }
}
