<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Application\NotificationPreferenceService;
use App\Modules\Notifications\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Modules\Notifications\Http\Resources\CommunicationPreferenceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET|PATCH /api/v1/me/notification-preferences`.
 *
 * Authorization already happened in `authorize.permission:notification.preference.*`;
 * the actor is resolved from the authenticated session and is the only user
 * these endpoints can ever address.
 */
final class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function show(Request $request): JsonResponse
    {
        $preference = $this->preferences->forUser($this->actor($request));

        return response()->json([
            'data' => (new CommunicationPreferenceResource($preference))->resolve($request),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $preference = $this->preferences->update(
            $this->actor($request),
            $request->validated(),
        );

        return response()->json([
            'data' => (new CommunicationPreferenceResource($preference))->resolve($request),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }
}
