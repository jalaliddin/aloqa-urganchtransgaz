<?php

declare(strict_types=1);

namespace App\Enums;

enum ApprovalType: string
{
    use HasOptions;

    case SafetyAgreement = 'safety_agreement';
    case OperationAgreement = 'operation_agreement';
    case SpecialistReview = 'specialist_review';
    case LeaveInPlaceDecision = 'leave_in_place_decision';
}
