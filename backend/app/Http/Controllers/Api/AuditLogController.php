<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/** Read-only. There are deliberately no update / delete endpoints. */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:64'],
            'auditable_type' => ['nullable', 'string', 'max:100'],
            'auditable_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $query = AuditLog::query()->visibleTo($request->user())
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['auditable_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['organization_id'] ?? null, fn ($q, $v) => $q->where('organization_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('auditable_label', 'like', "%{$term}%")->orWhere('user_name', 'like', "%{$term}%")->orWhere('reason', 'like', "%{$term}%")))
            ->latest('id');

        return AuditLogResource::collection($query->paginate($filters['per_page'] ?? 50)->withQueryString());
    }

    public function show(Request $request, AuditLog $auditLog): AuditLogResource
    {
        abort_unless($request->user()->can('audit.view') && $request->user()->canAccessOrganization($auditLog->organization_id), 403);

        return new AuditLogResource($auditLog);
    }

    /** Distinct event names for the filter. */
    public function events(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403);

        return response()->json(['data' => AuditLog::query()->visibleTo($request->user())->distinct()->orderBy('event')->pluck('event')]);
    }
}
