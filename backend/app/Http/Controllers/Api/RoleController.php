<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        $roles = Role::query()->with('permissions:id,name')->withCount('users')->get()
            ->sortBy(fn (Role $r) => array_search($r->name, array_keys(Rbac::ROLES), true))
            ->values()
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'label' => Rbac::roleLabel($role->name),
                'users_count' => $role->users_count,
                'editable' => $role->name !== Rbac::SUPER_ADMIN,
                'permissions' => $role->name === Rbac::SUPER_ADMIN ? array_keys(Rbac::PERMISSIONS) : $role->permissions->pluck('name')->values(),
            ]);

        return response()->json([
            'data' => $roles,
            'permissions' => collect(Rbac::PERMISSIONS)->map(fn ($label, $name) => ['name' => $name, 'label' => $label])->values(),
        ]);
    }

    /** Only the super administrator may change what a role can do. */
    public function updatePermissions(Request $request, string $role, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasRole(Rbac::SUPER_ADMIN), 403);
        abort_if($role === Rbac::SUPER_ADMIN, 422, "Super Admin roli o'zgartirilmaydi.");
        $model = Role::findByName($role, 'web');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::in(array_keys(Rbac::PERMISSIONS))],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $old = $model->permissions->pluck('name')->all();
        $model->syncPermissions($data['permissions']);
        $audit->log('role_permissions_changed', null, ['role' => $role, 'permissions' => $old], ['role' => $role, 'permissions' => $data['permissions']], $data['reason']);

        return response()->json(['message' => 'Rol ruxsatlari yangilandi.']);
    }
}
