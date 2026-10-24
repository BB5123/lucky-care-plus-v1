import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import {
  CursorPage,
  MedicationAdherence,
  MedicationAdherenceMeta,
  MedicationPlan,
  MedicationPlanFilters,
  MedicationPlanWriteBody,
  MedicationRecord,
  MedicationRecordWriteBody,
  parseMedicationAdherence,
  parseMedicationPlan,
  parseMedicationPlanPage,
  parseMedicationRecord,
} from './medication.models';

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
 * Read/write model for medication plans, reported doses and adherence.
 *
 * A `taken` row is self-reported, never clinical proof of ingestion; the UI
 * repeats that sentence next to every confirmation. Adherence values come
 * straight from the server and are never recomputed here.
 */
@Injectable({ providedIn: 'root' })
export class MedicationService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly plansState = signal<readonly MedicationPlan[]>([]);
  private readonly plansLoadingState = signal(false);
  private readonly plansErrorState = signal<string | null>(null);
  private readonly plansErrorStatusState = signal<number | null>(null);
  private readonly plansNextCursorState = signal<string | null>(null);

  private readonly adherenceState = signal<readonly MedicationAdherence[]>([]);
  private readonly adherenceMetaState = signal<MedicationAdherenceMeta>({
    days: 7,
    from: null,
    to: null,
  });
  private readonly adherenceLoadingState = signal(false);
  private readonly adherenceErrorState = signal<string | null>(null);
  private readonly adherenceDaysState = signal(7);

  readonly plans = this.plansState.asReadonly();
  readonly plansLoading = this.plansLoadingState.asReadonly();
  readonly plansError = this.plansErrorState.asReadonly();
  readonly plansErrorStatus = this.plansErrorStatusState.asReadonly();
  readonly plansNextCursor = this.plansNextCursorState.asReadonly();

  readonly adherence = this.adherenceState.asReadonly();
  readonly adherenceMeta = this.adherenceMetaState.asReadonly();
  readonly adherenceLoading = this.adherenceLoadingState.asReadonly();
  readonly adherenceError = this.adherenceErrorState.asReadonly();
  readonly adherenceDays = this.adherenceDaysState.asReadonly();

  private plansToken = 0;
  private adherenceToken = 0;

  /** Drops every cached collection, e.g. after a workspace or profile switch. */
  reset(): void {
    this.plansToken += 1;
    this.adherenceToken += 1;

    this.plansState.set([]);
    this.plansLoadingState.set(false);
    this.plansErrorState.set(null);
    this.plansErrorStatusState.set(null);
    this.plansNextCursorState.set(null);

    this.adherenceState.set([]);
    this.adherenceMetaState.set({ days: 7, from: null, to: null });
    this.adherenceLoadingState.set(false);
    this.adherenceErrorState.set(null);
    this.adherenceDaysState.set(7);
  }

  async listPlans(
    profileId: string,
    filters: MedicationPlanFilters = {},
    append = false,
  ): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      return;
    }

    const token = ++this.plansToken;

    this.plansLoadingState.set(true);
    this.plansErrorState.set(null);
    this.plansErrorStatusState.set(null);

    if (!append) {
      this.plansState.set([]);
      this.plansNextCursorState.set(null);
    }

    const url = withQuery(this.profilePath(workspaceId, profileId, 'medication-plans'), [
      ['status', filters.status ?? null],
      ['limit', filters.limit === undefined ? null : String(filters.limit)],
      ['cursor', filters.cursor ?? null],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.plansToken) {
        return;
      }

      const page: CursorPage<MedicationPlan> = parseMedicationPlanPage(response);
      this.plansState.update((current) => (append ? mergeById(current, page.items) : page.items));
      this.plansNextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.plansToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.plansState.set([]);
        this.plansNextCursorState.set(null);
      }

      this.plansErrorStatusState.set(status);
      this.plansErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.plansToken) {
        this.plansLoadingState.set(false);
      }
    }
  }

  async createPlan(profileId: string, body: MedicationPlanWriteBody): Promise<MedicationPlan> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        this.profilePath(workspaceId, profileId, 'medication-plans'),
        body,
      ),
    );
    const created = parseMedicationPlan(response.data);

    if (created === null) {
      throw new Error('invalid_medication_plan_payload');
    }

    this.plansState.update((current) => [created, ...current]);

    return created;
  }

  async updatePlan(
    profileId: string,
    planId: string,
    version: number,
    body: MedicationPlanWriteBody,
  ): Promise<MedicationPlan> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.patch<EntityEnvelope>(
        `${this.profilePath(workspaceId, profileId, 'medication-plans')}/${encodeURIComponent(
          planId,
        )}`,
        body,
        { headers: { 'If-Match': String(version) } },
      ),
    );
    const updated = parseMedicationPlan(response.data);

    if (updated === null) {
      throw new Error('invalid_medication_plan_payload');
    }

    this.replacePlan(updated);

    return updated;
  }

  async discontinuePlan(
    profileId: string,
    planId: string,
    version: number,
    reason: string,
  ): Promise<MedicationPlan> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        `${this.profilePath(
          workspaceId,
          profileId,
          'medication-plans',
        )}/${encodeURIComponent(planId)}/discontinue`,
        { reason },
        { headers: { 'If-Match': String(version) } },
      ),
    );
    const updated = parseMedicationPlan(response.data);

    if (updated === null) {
      throw new Error('invalid_medication_plan_payload');
    }

    this.replacePlan(updated);

    return updated;
  }

  async createRecord(
    profileId: string,
    body: MedicationRecordWriteBody,
  ): Promise<MedicationRecord> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        this.profilePath(workspaceId, profileId, 'medication-records'),
        body,
      ),
    );
    const created = parseMedicationRecord(response.data);

    if (created === null) {
      throw new Error('invalid_medication_record_payload');
    }

    // The adherence card must reflect the new reported dose immediately.
    await this.loadAdherence(profileId, this.adherenceDaysState());

    return created;
  }

  async loadAdherence(profileId: string, days = 7): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      return;
    }

    const token = ++this.adherenceToken;

    this.adherenceLoadingState.set(true);
    this.adherenceErrorState.set(null);
    this.adherenceDaysState.set(days);

    const url = withQuery(this.profilePath(workspaceId, profileId, 'medication-adherence'), [
      ['days', String(days)],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.adherenceToken) {
        return;
      }

      const result = parseMedicationAdherence(response);
      this.adherenceState.set(result.rows);
      this.adherenceMetaState.set(result.meta);
    } catch (error) {
      if (token !== this.adherenceToken) {
        return;
      }

      if (statusOf(error) === 403 || statusOf(error) === 404) {
        this.adherenceState.set([]);
      }

      this.adherenceErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.adherenceToken) {
        this.adherenceLoadingState.set(false);
      }
    }
  }

  private replacePlan(updated: MedicationPlan): void {
    this.plansState.update((current) =>
      current.map((item) => (item.id === updated.id ? updated : item)),
    );
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

  private profilePath(workspaceId: string, profileId: string, collection: string): string {
    return `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/profiles/${encodeURIComponent(
      profileId,
    )}/${collection}`;
  }
}
