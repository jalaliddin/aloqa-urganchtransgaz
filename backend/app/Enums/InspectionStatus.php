<?php

declare(strict_types=1);

namespace App\Enums;

/** Status of an inventory / technical inspection task. */
enum InspectionStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::InProgress, self::Rejected], true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Approved, self::Cancelled], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InProgress => 'blue',
            self::Submitted => 'violet',
            self::Approved => 'teal',
            self::Rejected => 'red',
            self::Cancelled => 'dark',
        };
    }
}
