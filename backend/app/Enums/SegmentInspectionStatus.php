<?php

declare(strict_types=1);

namespace App\Enums;

enum SegmentInspectionStatus: string
{
    use HasOptions;

    case NotInspected = 'not_inspected';
    case Scheduled = 'scheduled';
    case Inspected = 'inspected';
    case RequiresReinspection = 'requires_reinspection';

    public function needsInspection(): bool
    {
        return $this !== self::Inspected;
    }

    public function color(): string
    {
        return match ($this) {
            self::NotInspected => 'gray',
            self::Scheduled => 'blue',
            self::Inspected => 'teal',
            self::RequiresReinspection => 'orange',
        };
    }
}
