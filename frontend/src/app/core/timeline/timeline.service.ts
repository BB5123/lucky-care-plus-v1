import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import {
  TimelineEntry,
  TimelineFilters,
  mergeTimelineEntries,
  parseTimelinePage,
} from './timeline.models';

interface CollectionEnvelope {
  readonly data?: unknown;
  readonly meta?: unknown;
}

function statusOf(error: unknown): number | null {
  return error instanceof HttpErrorResponse ? error.status : null;
}

function withQuery(
  path: string,
  params: ReadonlyArray<readonly [string, string | null | undefined]>,
): string {
  const search = new URLSearchParams();

  for (const [key, value] of params) {
    if (value !== null && value !== undefined && value !== '') {
      search.set(key, value);
    }
  }

  const query = search.toString();

  return query === '' ? path : `${path}?${query}`;
}

/**
 * Read model for one profile's timeline.
 *
 * The backend decides visibility per entry and may return a redacted row. This
 * service only paginates, merges and exposes loader state; it never widens a
 * row or reconstructs an id that the API withheld.
 */
@Injectable({ providedIn: 'root' })
export class TimelineService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly itemsState = signal<readonly TimelineEntry[]>([]);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private readonly errorStatusState = signal<number | null>(null);
  private readonly nextCursorState = signal<string | null>(null);

  readonly items = this.itemsState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();
  readonly errorStatus = this.errorStatusState.asReadonly();
  readonly nextCursor = this.nextCursorState.asReadonly();

  private token = 0;

  reset(): void {
    this.token += 1;
    this.itemsState.set([]);
    this.loadingState.set(false);
    this.errorState.set(null);
    this.errorStatusState.set(null);
    this.nextCursorState.set(null);
  }

  async load(profileId: string, filters: TimelineFilters = {}, append = false): Promise<void> {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null || profileId === '') {
      this.reset();
      return;
    }

    const token = ++this.token;

    this.loadingState.set(true);
    this.errorState.set(null);
    this.errorStatusState.set(null);

    if (!append) {
      this.itemsState.set([]);
      this.nextCursorState.set(null);
    }

    const types =
      filters.types === undefined || filters.types === null || filters.types.length === 0
        ? null
        : filters.types.join(',');

    const url = withQuery(
      `${this.baseUrl}/workspaces/${encodeURIComponent(
        workspaceId,
      )}/profiles/${encodeURIComponent(profileId)}/timeline`,
      [
        ['from', filters.from ?? null],
        ['to', filters.to ?? null],
        ['types', types],
        ['limit', filters.limit === undefined ? null : String(filters.limit)],
        ['cursor', filters.cursor ?? null],
      ],
    );

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.token) {
        return;
      }

      const page = parseTimelinePage(response);
      this.itemsState.update((current) =>
        append ? mergeTimelineEntries(current, page.items) : page.items,
      );
      this.nextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.token) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.itemsState.set([]);
        this.nextCursorState.set(null);
      }

      this.errorStatusState.set(status);
      this.errorState.set(toUserMessage(error));
    } finally {
      if (token === this.token) {
        this.loadingState.set(false);
      }
    }
  }
}
