<?php

declare(strict_types=1);

namespace App\Enums;

enum MaterialReceiptStatus: string
{
    use HasOptions;

    /** Accepted; no discrepancy beyond tolerance. */
    case Received = 'received';
    /** Discrepancy detected — explanation and approval required. */
    case Discrepancy = 'discrepancy';
    case DiscrepancyApproved = 'discrepancy_approved';
    /** Explanation not accepted — recount / investigation required. */
    case DiscrepancyRejected = 'discrepancy_rejected';

    public function color(): string
    {
        return match ($this) {
            self::Received => 'teal',
            self::Discrepancy => 'orange',
            self::DiscrepancyApproved => 'blue',
            self::DiscrepancyRejected => 'red',
        };
    }
}
