<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\AccessibleOrganization;
use App\Services\AuditLogger;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'organization_id' => ['nullable', 'integer'],
            'role' => ['nullable', Rule::in(array_keys(Rbac::ROLES))],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $ids = $request->user()->visibleOrganizationIds();

        $query = User::query()->with(['organization:id,name,short_name', 'roles'])
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name');

        return UserResource::collection($query->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    /** Active users in the caller's scope (for "assignee" selects across modules). */
    public function directory(Request $request): JsonResponse
    {
        $ids = $request->user()->visibleOrganizationIds();
        $users = User::query()->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('organization_id', $ids))
            ->when($request->integer('organization_id'), fn ($q, $id) => $q->where('organization_id', $id))
            ->orderBy('name')->limit(500)->get(['id', 'name', 'position', 'organization_id']);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create(collect($data)->except('roles')->all());
            $user->syncRoles($data['roles']);

            return $user;
        });
        $this->audit->log('roles_assigned', $user, null, ['roles' => $data['roles']]);

        return (new UserResource($user->load('organization', 'roles')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && (($data['is_active'] ?? true) === false || isset($data['roles']) && ! in_array(Rbac::SUPER_ADMIN, $data['roles'], true) && $user->hasRole(Rbac::SUPER_ADMIN))) {
            abort(422, "O'z hisobingizni o'chirib qo'yish yoki administrator rolidan voz kechish mumkin emas.");
        }

        DB::transaction(function () use ($user, $data): void {
            $user->update(collect($data)->except('roles')->filter(fn ($v, $k) => $k !== 'password' || filled($v))->all());

            if (isset($data['roles'])) {
                $old = $user->getRoleNames()->all();
                $user->syncRoles($data['roles']);
                $this->audit->log('roles_assigned', $user, ['roles' => $old], ['roles' => $data['roles']]);
            }
        });

        return new UserResource($user->load('organization', 'roles'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        $assignable = $request->user()->hasRole(Rbac::SUPER_ADMIN)
            ? array_keys(Rbac::ROLES)
            : array_values(array_diff(array_keys(Rbac::ROLES), [Rbac::SUPER_ADMIN]));
        $required = $user === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'position' => ['nullable', 'string', 'max:255'],
            'organization_id' => [$required, 'integer', new AccessibleOrganization($request->user())],
            'password' => [$user === null ? 'required' : 'nullable', 'string', Password::defaults()],
            'is_active' => ['boolean'],
            'roles' => [$required, 'array', 'min:1'],
            'roles.*' => [Rule::in($assignable)],
        ]);
    }
}
