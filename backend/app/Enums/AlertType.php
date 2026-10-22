<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertType: string
{
    use HasOptions;

    case LengthDiscrepancy = 'length_discrepancy';
    case MaterialDiscrepancy = 'material_discrepancy';
    case MetalDiscrepancy = 'metal_discrepancy';
    case MissingDocument = 'missing_document';
    case UnauthorizedStatusChange = 'unauthorized_status_change';
    case SegregationOfDuties = 'segregation_of_duties';
    case DuplicateDismantlingOrder = 'duplicate_dismantling_order';
    case SuspiciousGeometryChange = 'suspicious_geometry_change';
    case SuspiciousLengthChange = 'suspicious_length_change';
}
