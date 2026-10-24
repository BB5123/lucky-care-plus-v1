import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import {
  CareTask,
  CareTaskFilters,
  CursorPage,
  EvidenceWriteBody,
  TaskAction,
  TaskEvidence,
  TaskEvent,
  parseCareTask,
  parseCareTaskPage,
  parseTaskEvidence,
  parseTaskEvidenceList,
  parseTaskEventList,
} from './caretask.models';

interface CollectionEnvelope {
  readonly data?: unknown;
  readonly meta?: unknown;
}

interface EntityEnvelope {
  readonly data?: unknown;
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

function mergeById<T extends { readonly id: string }>(
  current: readonly T[],
  incoming: readonly T[],
): readonly T[] {
  const seen = new Set(current.map((item) => item.id));
  const added = incoming.filter((item) => !seen.has(item.id));

  return [...current, ...added];
}

/**
 * Read/write model for care tasks.
 *
 * The server decides whether a reader gets the full DTO or the safe card; this
 * service keeps that union intact. Mutations always send `If-Match` with the
 * version the dialog was rendered from, so a stale screen fails with 412
 * instead of overwriting someone else's transition.
 */
@Injectable({ providedIn: 'root' })
export class CareTaskService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly tasksState = signal<readonly CareTask[]>([]);
  private readonly tasksLoadingState = signal(false);
  private readonly tasksErrorState = signal<string | null>(null);
  private readonly tasksErrorStatusState = signal<number | null>(null);
  private readonly tasksNextCursorState = signal<string | null>(null);

  private readonly detailState = signal<CareTask | null>(null);
  private readonly detailLoadingState = signal(false);
  private readonly detailErrorState = signal<string | null>(null);
  private readonly detailErrorStatusState = signal<number | null>(null);

  private readonly evidenceState = signal<readonly TaskEvidence[]>([]);
  private readonly evidenceLoadingState = signal(false);
  private readonly evidenceErrorState = signal<string | null>(null);

  private readonly eventsState = signal<readonly TaskEvent[]>([]);
  private readonly completedByState = signal<string | null>(null);

  readonly tasks = this.tasksState.asReadonly();
  readonly tasksLoading = this.tasksLoadingState.asReadonly();
  readonly tasksError = this.tasksErrorState.asReadonly();
  readonly tasksErrorStatus = this.tasksErrorStatusState.asReadonly();
  readonly tasksNextCursor = this.tasksNextCursorState.asReadonly();

  readonly detail = this.detailState.asReadonly();
  readonly detailLoading = this.detailLoadingState.asReadonly();
  readonly detailError = this.detailErrorState.asReadonly();
  readonly detailErrorStatus = this.detailErrorStatusState.asReadonly();

  readonly evidence = this.evidenceState.asReadonly();
  readonly evidenceLoading = this.evidenceLoadingState.asReadonly();
  readonly evidenceError = this.evidenceErrorState.asReadonly();

  readonly events = this.eventsState.asReadonly();
  readonly completedBy = this.completedByState.asReadonly();

  private tasksToken = 0;
  private detailToken = 0;

  reset(): void {
    this.tasksToken += 1;
    this.detailToken += 1;

    this.tasksState.set([]);
    this.tasksLoadingState.set(false);
    this.tasksErrorState.set(null);
    this.tasksErrorStatusState.set(null);
    this.tasksNextCursorState.set(null);

    this.detailState.set(null);
    this.detailLoadingState.set(false);
    this.detailErrorState.set(null);
    this.detailErrorStatusState.set(null);

    this.evidenceState.set([]);
    this.evidenceLoadingState.set(false);
    this.evidenceErrorState.set(null);

    this.eventsState.set([]);
    this.completedByState.set(null);
  }

  async list(filters: CareTaskFilters = {}, append = false): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null) {
      return;
    }

    const token = ++this.tasksToken;

    this.tasksLoadingState.set(true);
    this.tasksErrorState.set(null);
    this.tasksErrorStatusState.set(null);

    if (!append) {
      this.tasksState.set([]);
      this.tasksNextCursorState.set(null);
    }

    const url = withQuery(
      `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/care-tasks`,
      [
        ['status', filters.status ?? null],
        ['tier', filters.tier ?? null],
        ['assignee', filters.assignee ?? null],
        ['overdue', filters.overdue === true ? 'true' : null],
        ['limit', filters.limit === undefined ? null : String(filters.limit)],
        ['cursor', filters.cursor ?? null],
      ],
    );

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.tasksToken) {
        return;
      }

      const page: CursorPage<CareTask> = parseCareTaskPage(response);
      this.tasksState.update((current) => (append ? mergeById(current, page.items) : page.items));
      this.tasksNextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.tasksToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.tasksState.set([]);
        this.tasksNextCursorState.set(null);
      }

      this.tasksErrorStatusState.set(status);
      this.tasksErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.tasksToken) {
        this.tasksLoadingState.set(false);
      }
    }
  }

  async loadDetail(taskId: string): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || taskId === '') {
      return;
    }

    const token = ++this.detailToken;

    this.detailLoadingState.set(true);
    this.detailErrorState.set(null);
    this.detailErrorStatusState.set(null);
    this.evidenceState.set([]);
    this.eventsState.set([]);
    this.completedByState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<EntityEnvelope>(
          `${this.baseUrl}/workspaces/${encodeURIComponent(
            workspaceId,
          )}/care-tasks/${encodeURIComponent(taskId)}`,
        ),
      );

      if (token !== this.detailToken) {
        return;
      }

      const task = parseCareTask(response.data);

      if (task === null) {
        throw new Error('invalid_care_task_payload');
      }

      this.detailState.set(task);

      if (task.kind === 'full' && task.tier === 'clinical') {
        await this.loadEvidence(taskId);

        if (task.status === 'completed' && task.verification === 'pending') {
          await this.loadEvents(taskId);
        }
      }
    } catch (error) {
      if (token !== this.detailToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.detailState.set(null);
      }

      this.detailErrorStatusState.set(status);
      this.detailErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.detailToken) {
        this.detailLoadingState.set(false);
      }
    }
  }

  async loadEvidence(taskId: string): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || taskId === '') {
      return;
    }

    this.evidenceLoadingState.set(true);
    this.evidenceErrorState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<CollectionEnvelope>(this.actionPath(workspaceId, taskId, 'evidence')),
      );
      this.evidenceState.set(parseTaskEvidenceList(response.data));
    } catch (error) {
      // A safe-card reader may see the task but not its evidence; that is a
      // normal 403, not a screen-level failure.
      this.evidenceErrorState.set(toUserMessage(error));
    } finally {
      this.evidenceLoadingState.set(false);
    }
  }

  async loadEvents(taskId: string): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || taskId === '') {
      return;
    }

    try {
      const response = await firstValueFrom(
        this.http.get<CollectionEnvelope>(this.actionPath(workspaceId, taskId, 'events')),
      );
      const events = parseTaskEventList(response.data);
      this.eventsState.set(events);

      const completed = [...events].reverse().find((event) => event.event === 'completed');
      this.completedByState.set(
        completed === undefined || completed.actor_id === null ? null : String(completed.actor_id),
      );
    } catch {
      // Events are only used to hide the verify/reject pair from the completer.
      // The server still enforces separation of duties when they are missing.
      this.eventsState.set([]);
      this.completedByState.set(null);
    }
  }

  /**
   * One endpoint per transition (assign/accept/start/complete/verify/...).
   * The caller must pass the version its screen was rendered from.
   */
  async act(
    taskId: string,
    version: number,
    action: TaskAction,
    body: Readonly<Record<string, unknown>> = {},
  ): Promise<CareTask> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(this.actionPath(workspaceId, taskId, action), body, {
        headers: { 'If-Match': String(version) },
      }),
    );
    const updated = parseCareTask(response.data);

    if (updated === null) {
      throw new Error('invalid_care_task_payload');
    }

    this.detailState.set(updated);
    this.tasksState.update((current) =>
      current.map((item) => (item.id === updated.id ? updated : item)),
    );

    // A completed clinical task has just become verifiable; reload its events
    // so the current completer does not see a verify button they cannot use.
    if (
      updated.kind === 'full' &&
      updated.tier === 'clinical' &&
      updated.status === 'completed' &&
      updated.verification === 'pending'
    ) {
      await this.loadEvents(updated.id);
    }

    return updated;
  }

  async addEvidence(
    taskId: string,
    body: EvidenceWriteBody,
    version?: number,
  ): Promise<TaskEvidence> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        this.actionPath(workspaceId, taskId, 'evidence'),
        body,
        version === undefined ? {} : { headers: { 'If-Match': String(version) } },
      ),
    );
    const created = parseTaskEvidence(response.data);

    if (created === null) {
      throw new Error('invalid_task_evidence_payload');
    }

    this.evidenceState.update((current) => [created, ...current]);

    return created;
  }

  private actionPath(workspaceId: string, taskId: string, action: string): string {
    return `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/care-tasks/${encodeURIComponent(taskId)}/${action}`;
  }

  private workspaceId(): string | null {
    return this.workspace.currentWorkspace()?.workspace_id ?? null;
  }

  private requireWorkspace(): string {
    const workspaceId = this.workspaceId();

    if (workspaceId === null) {
      throw new Error('workspace_missing');
    }

    return workspaceId;
  }
}
