<?php

declare(strict_types=1);

namespace App\Enums;

/** Dismantling state of a cable segment / line (spec 4.2). Driven by dismantling orders. */
enum DismantlingStatus: string
{
    use HasOptions;

    case NotPlanned = 'not_planned';
    case AwaitingReview = 'awaiting_review';
    case AwaitingAgreement = 'awaiting_agreement';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case PartiallyCompleted = 'partially_completed';
    case Completed = 'completed';
    case LeaveInPlacePending = 'leave_in_place_pending';
    case LeftInPlace = 'left_in_place';
    case Suspended = 'suspended';

    /** Progress order used when aggregating segments into a line status. */
    public function progress(): int
    {
        return match ($this) {
            self::NotPlanned => 0,
            self::AwaitingReview => 1,
            self::AwaitingAgreement => 2,
            self::LeaveInPlacePending => 2,
            self::Suspended => 3,
            self::Approved => 3,
            self::InProgress => 4,
            self::PartiallyCompleted => 5,
            self::Completed => 6,
            self::LeftInPlace => 6,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotPlanned => 'gray',
            self::AwaitingReview => 'indigo',
            self::AwaitingAgreement => 'violet',
            self::Approved => 'cyan',
            self::InProgress => 'blue',
            self::PartiallyCompleted => 'yellow',
            self::Completed => 'teal',
            self::LeaveInPlacePending => 'grape',
            self::LeftInPlace => 'dark',
            self::Suspended => 'red',
        };
    }
}
