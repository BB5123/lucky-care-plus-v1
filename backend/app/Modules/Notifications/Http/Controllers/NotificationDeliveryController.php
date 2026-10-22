<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Domain\NotificationDeliveryNotFoundException;
use App\Modules\Notifications\Http\Requests\ListNotificationDeliveriesRequest;
use App\Modules\Notifications\Http\Resources\NotificationDeliveryResource;
use App\Modules\Notifications\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/me/notifications/deliveries` and
 * `POST /api/v1/me/notifications/{delivery}/read`.
 *
 * Every query is filtered by the authenticated actor's id, in addition to the
 * RLS policy keyed on `app.user_id`. A delivery belonging to another member is
 * therefore a 404, never a 403 that would confirm it exists.
 */
final class NotificationDeliveryController extends Controller
{
    public function index(ListNotificationDeliveriesRequest $request): JsonResponse
    {
        $data = $request->validated();

        $query = NotificationDelivery::query()
            ->where('recipient_user_id', $this->actor($request)->id);

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (isset($data['channel'])) {
            $query->where('channel', $data['channel']);
        }

        if (filter_var($data['unread'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('read_at');
        }

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                (int) ($data['limit'] ?? 20),
                ['*'],
                'cursor',
                $data['cursor'] ?? null,
            );

        return response()->json([
            'data' => NotificationDeliveryResource::collection($page->items())->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor()?->encode(),
                'limit' => $page->perPage(),
            ],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $delivery = NotificationDelivery::query()
            ->where('recipient_user_id', $this->actor($request)->id)
            ->whereKey((string) $request->route('delivery'))
            ->first();

        if (! $delivery instanceof NotificationDelivery) {
            throw new NotificationDeliveryNotFoundException;
        }

        // Idempotent: `read_at` is set once and never moved.
        if ($delivery->read_at === null) {
            $delivery->forceFill(['read_at' => now()])->save();
        }

        return response()->json([
            'data' => (new NotificationDeliveryResource($delivery))->resolve($request),
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
