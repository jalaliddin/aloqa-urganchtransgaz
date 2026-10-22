<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    /** Organisations within the user's scope (for filters and selects). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $ids = $request->user()->visibleOrganizationIds();

        return OrganizationResource::collection(
            Organization::query()
                ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
                ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
                ->orderBy('type')->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request): OrganizationResource
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return new OrganizationResource(Organization::query()->create($this->validated($request)));
    }

    public function update(Request $request, Organization $organization): OrganizationResource
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $organization->update($this->validated($request, $organization));

        return new OrganizationResource($organization);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Organization $organization = null): array
    {
        return $request->validate([
            'parent_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'code' => ['required', 'string', 'max:32', Rule::unique('organizations', 'code')->ignore($organization?->id)],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
