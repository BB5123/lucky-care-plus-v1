<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ProblemDetails;
use App\Models\User;
use App\Modules\Accounts\Services\AuthAudit;
use App\Modules\Accounts\Services\TokenService;
use App\Modules\Workspace\Services\MembershipDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Registration, login, logout, and `/me`.
 *
 * There is no role or permission comparison here: workspace access decisions
 * belong to `Authorizer`; this controller only manages the global identity.
 */
final class AuthController extends Controller
{
    public function register(Request $request, TokenService $tokens): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed', Password::defaults()],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'password' => $validated['password'],
            'last_login_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $tokens->issue($user, $validated['device_name'] ?? 'api'),
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(Request $request, TokenService $tokens, AuthAudit $audit): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $credentials['email'] = Str::lower($credentials['email']);
        $credentials['device_name'] = $credentials['device_name'] ?? null;

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user instanceof User || ! Hash::check($credentials['password'], $user->password)) {
            // One generic answer: never reveal whether the address exists.
            $audit->loginDenied($user);

            return ProblemDetails::make(
                $request,
                422,
                'invalid_credentials',
                'Invalid credentials',
                'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
            );
        }

        // A successful login is the activity clock for the inactivity flow.
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $tokens->issue($user, $credentials['device_name'] ?? 'api'),
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request, TokenService $tokens): JsonResponse
    {
        $tokens->revokeCurrent($request);

        return response()->json(null, 204);
    }

    public function me(Request $request, MembershipDirectory $directory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspaces = $directory->forUser($user)
            ->map(fn (array $entry): array => [
                'id' => $entry['workspace']->id,
                'workspace_id' => $entry['workspace']->id,
                'membership_id' => $entry['membership']->id,
                'name' => $entry['workspace']->name,
                'role' => $entry['membership']->role,
                'status' => $entry['membership']->status,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'workspaces' => $workspaces,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
        ];
    }
}
