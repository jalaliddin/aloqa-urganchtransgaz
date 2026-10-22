<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ComplexityFlag;
use App\Enums\DismantlingStatus;
use App\Enums\LayingType;
use App\Enums\RiskLevel;
use App\Enums\SegmentInspectionStatus;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CableSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $segment = $this->route('cableSegment');

        return $segment !== null
            ? $this->user()->can('update', $segment)
            : $this->user()->can('update', $this->route('cableLine'));
    }

    public function rules(): array
    {
        $creating = $this->route('cableSegment') === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'start_point' => ['nullable', 'string', 'max:255'],
            'end_point' => ['nullable', 'string', 'max:255'],
            'location_description' => ['nullable', 'string', 'max:255'],
            'laying_type' => [$required, Rule::enum(LayingType::class)],
            'depth_m' => ['nullable', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
            'design_length_m' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'technical_condition' => ['sometimes', Rule::enum(TechnicalCondition::class)],
            'usage_status' => ['sometimes', Rule::enum(UsageStatus::class)],
            'risk_level' => ['sometimes', Rule::enum(RiskLevel::class)],
            'complexity_flags' => ['nullable', 'array'],
            'complexity_flags.*' => [Rule::enum(ComplexityFlag::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'geometry' => ['nullable', 'array'],
            // Workflow-owned: accepted only so that change attempts can be detected and refused
            'inspection_status' => ['sometimes', Rule::enum(SegmentInspectionStatus::class)],
            'dismantling_status' => ['sometimes', Rule::enum(DismantlingStatus::class)],
            'measured_length_m' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
