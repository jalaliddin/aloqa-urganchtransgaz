<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentType: string
{
    use HasOptions;

    case UztransgazOrder = 'uztransgaz_order';
    case CompanyOrder = 'company_order';
    case CommissionComposition = 'commission_composition';
    case CommissionProtocol = 'commission_protocol';
    case TechnicalConclusion = 'technical_conclusion';
    case WorkPermit = 'work_permit';
    case Agreement = 'agreement';
    case DismantlingAct = 'dismantling_act';
    case WarehouseReceipt = 'warehouse_receipt';
    case WriteOff = 'write_off';
    case LeaveInPlaceDecision = 'leave_in_place_decision';
    case TechnicalPassport = 'technical_passport';
    case OldScheme = 'old_scheme';
    case Photo = 'photo';
    case Video = 'video';
    case Other = 'other';
}
