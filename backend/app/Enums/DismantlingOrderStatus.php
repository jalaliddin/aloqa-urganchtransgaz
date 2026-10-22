<?php

declare(strict_types=1);

namespace App\Enums;

enum DismantlingOrderStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case PendingAgreement = 'pending_agreement';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case PartiallyCompleted = 'partially_completed';
    case Completed = 'completed';
    case LeaveInPlacePending = 'leave_in_place_pending';
    case LeftInPlace = 'left_in_place';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /**
     * Allowed manual transitions. Business preconditions (agreements, documents,
     * reasons) are enforced separately by DismantlingWorkflow.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingReview, self::Cancelled],
            self::PendingReview => [self::PendingAgreement, self::LeaveInPlacePending, self::Cancelled],
            self::PendingAgreement => [self::Approved, self::LeaveInPlacePending, self::PendingReview, self::Cancelled],
            self::Approved => [self::InProgress, self::Suspended, self::Cancelled],
            self::InProgress => [self::PartiallyCompleted, self::Completed, self::Suspended, self::LeaveInPlacePending],
            self::PartiallyCompleted => [self::InProgress, self::Completed, self::Suspended, self::LeaveInPlacePending],
            self::Suspended => [self::PendingReview, self::InProgress, self::Cancelled],
            self::LeaveInPlacePending => [self::LeftInPlace, self::PendingReview],
            self::Completed, self::LeftInPlace, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Orders in these states still "own" their segments (a second order is a duplicate). */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::LeftInPlace, self::Cancelled], true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Work is allowed / happening, so progress can be recorded. */
    public function acceptsProgress(): bool
    {
        return in_array($this, [self::InProgress, self::PartiallyCompleted], true);
    }

    public function segmentStatus(): DismantlingStatus
    {
        return match ($this) {
            self::Draft, self::PendingReview => DismantlingStatus::AwaitingReview,
            self::PendingAgreement => DismantlingStatus::AwaitingAgreement,
            self::Approved => DismantlingStatus::Approved,
            self::InProgress => DismantlingStatus::InProgress,
            self::PartiallyCompleted => DismantlingStatus::PartiallyCompleted,
            self::Completed => DismantlingStatus::Completed,
            self::LeaveInPlacePending => DismantlingStatus::LeaveInPlacePending,
            self::LeftInPlace => DismantlingStatus::LeftInPlace,
            self::Suspended => DismantlingStatus::Suspended,
            self::Cancelled => DismantlingStatus::NotPlanned,
        };
    }

    public function color(): string
    {
        return $this === self::Draft || $this === self::Cancelled ? 'gray' : $this->segmentStatus()->color();
    }
}
