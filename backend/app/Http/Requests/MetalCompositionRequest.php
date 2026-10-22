<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CompositionSourceType;
use App\Enums\Metal;
use App\Models\CableMetalComposition;
use App\Services\MetalCompositionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * kg per metre of aluminium, lead and copper. An empty value means "unknown" (stored as NULL)
 * and is never turned into 0; 0 is an explicit, verified "none".
 */
class MetalCompositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scope = app(MetalCompositionService::class)->resolveScope((string) $this->input('scope_type'), (int) $this->input('scope_id'));

        return $this->user()->can('submitFor', [CableMetalComposition::class, $scope]);
    }

    public function rules(): array
    {
        $metal = ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,6'];

        return [
            'scope_type' => ['required', Rule::in(array_keys(MetalCompositionService::SCOPES))],
            'scope_id' => ['required', 'integer'],
            'aluminium_kg_per_m' => $metal,
            'lead_kg_per_m' => $metal,
            'copper_kg_per_m' => $metal,
            'source_type' => ['required', Rule::enum(CompositionSourceType::class)],
            'source_reference' => ['required', 'string', 'min:3', 'max:255'],
            'document_id' => [
                Rule::requiredIf($this->input('source_type') === CompositionSourceType::SupportingDocument->value),
                'nullable', 'integer',
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.decimal' => "Qiymat ko'pi bilan 6 kasr xonali bo'lishi kerak.",
            'document_id.required' => 'Manba "tasdiqlovchi hujjat" bo\'lsa, hujjatni tanlang.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $provided = collect(Metal::cases())->filter(fn (Metal $m) => $this->input($m->compositionColumn()) !== null);
                if ($provided->isEmpty()) {
                    $validator->errors()->add('aluminium_kg_per_m', "Kamida bitta metall qiymatini kiriting (tekshirilgan nol uchun 0). Bo'sh qoldirilgan maydon \"noma'lum\" degani.");
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'aluminium_kg_per_m' => 'Alyuminiy (kg/m)',
            'lead_kg_per_m' => "Qo'rg'oshin (kg/m)",
            'copper_kg_per_m' => 'Mis (kg/m)',
            'source_type' => 'Manba turi',
            'source_reference' => 'Manba',
        ];
    }
}
