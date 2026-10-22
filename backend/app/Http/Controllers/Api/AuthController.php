<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\CableLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function login(Request $request): JsonResponse
    {
        // Cookie-based SPA auth: the request must come from a configured stateful domain (SANCTUM_STATEFUL_DOMAINS)
        abort_unless($request->hasSession(), 400, "Sessiya ochilmadi: so'rov ruxsat etilgan domendan yuborilishi kerak.");

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        $maxAttempts = (int) config('aloqa.auth.max_login_attempts');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $this->audit->log('login_locked', null, null, ['email' => $credentials['email']]);

            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])],
            ])->status(429);
        }

        $user = User::query()->where('email', $credentials['email'])->first();
        // Constant-time path even when the e-mail is unknown (no user enumeration)
        $valid = Hash::check($credentials['password'], $user?->password ?? '$2y$12$'.str_repeat('a', 53));

        if ($user === null || ! $valid) {
            RateLimiter::hit($key, (int) config('aloqa.auth.lockout_seconds'));
            $this->audit->log('login_failed', null, null, ['email' => $credentials['email']]);

            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        if (! $user->is_active) {
            $this->audit->log('login_inactive', $user);

            throw ValidationException::withMessages(['email' => [__('auth.inactive')]]);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $this->audit->log('login', $user);

        return $this->profile($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log('logout', $request->user());

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Tizimdan chiqildi.']);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->profile($request->user());
    }

    private function profile(User $user): JsonResponse
    {
        $user->load('organization', 'roles');

        return response()->json([
            'data' => [
                ...(new UserResource($user))->resolve(),
                'permissions' => $user->hasRole('super_admin')
                    ? array_keys(Rbac::PERMISSIONS)
                    : $user->getAllPermissions()->pluck('name')->values(),
                'can_view_all_organizations' => $user->canViewAllOrganizations(),
                // Lets the UI warn that sample records are present, so they are never mistaken for real data
                'demo_data_present' => CableLine::query()->where('is_demo', true)->exists(),
            ],
        ]);
    }
}
