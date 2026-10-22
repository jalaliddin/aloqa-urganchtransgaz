<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ComplexityFlag;
use App\Enums\LaidYearPrecision;
use App\Enums\LayingType;
use App\Enums\RiskLevel;
use App\Enums\TechnicalCondition;
use App\Enums\UsageStatus;
use App\Models\CableLine;
use App\Models\GasPipelineAsset;
use App\Rules\AccessibleOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CableLineRequest extends FormRequest
{
    /** Authorisation runs before validation so unauthorised users get 403, not field errors. */
    public function authorize(): bool
    {
        $line = $this->route('cableLine');

        return $line === null
            ? $this->user()->can('create', CableLine::class)
            : $this->user()->can('update', $line) || $this->user()->can('updateFinancial', $line);
    }

    public function rules(): array
    {
        /** @var CableLine|null $line */
        $line = $this->route('cableLine');
        $creating = $line === null;
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:255'],
            'cable_code' => ['nullable', 'string', 'max:64', Rule::unique('cable_lines', 'cable_code')->ignore($line?->id)],
            // Type and brand come from the cable type dictionary (the source of kg/m), never free text.
            // New lines take an active type; an existing line may keep its current type after it was deactivated.
            'cable_type_id' => [
                ...($creating ? ['required'] : ['sometimes', 'required']),
                'integer',
                Rule::exists('cable_types', 'id')->whereNull('deleted_at')->where(
                    fn ($q) => $q->where(fn ($w) => $w->where('is_active', true)->when($line?->cable_type_id, fn ($c, $id) => $c->orWhere('id', $id))),
                ),
            ],
            'core_count' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'laid_year' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'laid_year_precision' => [$required, Rule::enum(LaidYearPrecision::class)],
            'balance_holder_id' => [$required, 'integer', new AccessibleOrganization($this->user())],
            'organization_id' => [$required, 'integer', new AccessibleOrganization($this->user())],
            'gas_pipeline_asset_id' => ['nullable', 'integer', Rule::exists(GasPipelineAsset::class, 'id')->whereNull('deleted_at')],
            'object_name' => ['nullable', 'string', 'max:255'],
            'start_point' => ['nullable', 'string', 'max:255'],
            'end_point' => ['nullable', 'string', 'max:255'],
            'design_length_m' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_demo' => ['prohibited'],
        ];

        if ($this->user()?->can('cable_lines.update_financial')) {
            $rules += [
                'inventory_number' => ['nullable', 'string', 'max:64'],
                'initial_cost' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'accumulated_depreciation' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'residual_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            ];
        } else {
            foreach (CableLine::FINANCIAL_FIELDS as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        if ($creating) {
            $rules += [
                'segments' => ['nullable', 'array', 'max:200'],
                'segments.*.name' => ['nullable', 'string', 'max:255'],
                'segments.*.start_point' => ['nullable', 'string', 'max:255'],
                'segments.*.end_point' => ['nullable', 'string', 'max:255'],
                'segments.*.location_description' => ['nullable', 'string', 'max:255'],
                'segments.*.laying_type' => ['nullable', Rule::enum(LayingType::class)],
                'segments.*.depth_m' => ['nullable', 'numeric', 'min:0', 'max:50'],
                'segments.*.design_length_m' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
                'segments.*.technical_condition' => ['nullable', Rule::enum(TechnicalCondition::class)],
                'segments.*.usage_status' => ['nullable', Rule::enum(UsageStatus::class)],
                'segments.*.risk_level' => ['nullable', Rule::enum(RiskLevel::class)],
                'segments.*.complexity_flags' => ['nullable', 'array'],
                'segments.*.complexity_flags.*' => [Rule::enum(ComplexityFlag::class)],
                'segments.*.geometry' => ['nullable', 'array'],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'cable_type_id.required' => "Kabel turini ma'lumotnomadan tanlang.",
            'cable_type_id.exists' => 'Tanlangan kabel turi topilmadi yoki faol emas.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $initial = $this->input('initial_cost');
                $depreciation = $this->input('accumulated_depreciation');
                if (is_numeric($initial) && is_numeric($depreciation) && (float) $depreciation > (float) $initial) {
                    $validator->errors()->add('accumulated_depreciation', "Jamg'arilgan amortizatsiya boshlang'ich qiymatdan oshmasligi kerak.");
                }
                if ($this->input('laid_year_precision') === LaidYearPrecision::Exact->value && blank($this->input('laid_year'))) {
                    $validator->errors()->add('laid_year', "Aniq yil tanlangan bo'lsa, yil kiritilishi shart.");
                }
            },
        ];
    }
}
