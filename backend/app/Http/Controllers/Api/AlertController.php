<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Alert::class);
        $filters = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(AlertStatus::class)],
            'type' => ['nullable', Rule::enum(AlertType::class)],
            'severity' => ['nullable', Rule::enum(AlertSeverity::class)],
            'organization_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:48'],
            'subject_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = Alert::query()->visibleTo($request->user())
            ->with(['organization:id,name,short_name', 'triggeredBy:id,name', 'resolvedBy:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->whereIn('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where('organization_id', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->latest('updated_at');

        return AlertResource::collection($query->paginate($filters['per_page'] ?? 20)->withQueryString());
    }

    public function acknowledge(Request $request, Alert $alert): AlertResource
    {
        $this->authorize('resolve', $alert);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return new AlertResource($this->alerts->acknowledge($alert, $request->user(), $data['note'] ?? null));
    }

    public function resolve(Request $request, Alert $alert): AlertResource
    {
        $this->authorize('resolve', $alert);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000']]);

        return new AlertResource($this->alerts->resolve($alert, $request->user(), $data['note']));
    }
}
