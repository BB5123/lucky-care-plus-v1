<?php

namespace App\Modules\Timeline\Application;

use App\Models\User;
use App\Modules\HealthRecord\Models\HealthProfile;
use App\Modules\Timeline\Http\Resources\TimelineEntryResource;
use App\Modules\Timeline\Models\TimelineEntry;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reads one profile's timeline page and marks each entry visible/redacted.
 *
 * Ordering is newest-first on `(occurred_at, id)`, which matches the
 * `(profile_id, occurred_at desc)` index and gives the cursor a stable tie
 * breaker for entries projected in the same second.
 */
final class TimelineAssembler
{
    public function __construct(private readonly TimelineVisibility $visibility) {}

    /**
     * @param  list<string>  $types
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, limit: int}}
     */
    public function page(
        Request $request,
        Workspace $workspace,
        User $actor,
        HealthProfile $profile,
        array $types,
        ?string $from,
        ?string $to,
        int $limit,
        ?string $cursor,
    ): array {
        $query = TimelineEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('profile_id', $profile->id)
            ->whereIn('type', $types);

        if ($from !== null && $from !== '') {
            $query->where('occurred_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to !== null && $to !== '') {
            $query->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $page = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        $visible = $this->visibility->visibleEntryIds(
            $workspace,
            $actor,
            (string) $profile->id,
            $page->getCollection(),
        );

        return [
            'data' => $this->resources($request, $page, $visible),
            'meta' => $this->meta($page),
        ];
    }

    /**
     * @param  array<string, bool>  $visible
     * @return list<array<string, mixed>>
     */
    private function resources(Request $request, CursorPaginator $page, array $visible): array
    {
        $items = [];

        foreach ($page->items() as $entry) {
            $items[] = (new TimelineEntryResource($entry))
                ->redacted(! ($visible[(string) $entry->id] ?? false))
                ->resolve($request);
        }

        return $items;
    }

    /**
     * @return array{next_cursor: ?string, limit: int}
     */
    private function meta(CursorPaginator $page): array
    {
        return [
            'next_cursor' => $page->nextCursor()?->encode(),
            'limit' => $page->perPage(),
        ];
    }
}
