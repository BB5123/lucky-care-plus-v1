import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import {
  Allergy,
  AllergyFilters,
  AllergyWriteBody,
  Condition,
  ConditionFilters,
  ConditionWriteBody,
  Measurement,
  MeasurementCreateBody,
  MeasurementFilters,
  TrendPoint,
  parseAllergy,
  parseAllergyPage,
  parseCondition,
  parseConditionPage,
  parseMeasurement,
  parseMeasurementPage,
  parseTrendPoints,
} from './health-record.models';

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
 * Read/write model for the Health Vault API.
 *
 * Every request is workspace-scoped and profile-scoped from the route, never
 * from a body field (AP-002). Collection signals keep the last page for the
 * active profile so a successful create/update can repaint without a reload.
 *
 * List methods record failures in their own error signals; create/update
 * methods let the error escape so a form can bind `errors{}` or a 412 to the
 * right control.
 */
@Injectable({ providedIn: 'root' })
export class HealthRecordService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly measurementsState = signal<readonly Measurement[]>([]);
  private readonly measurementsLoadingState = signal(false);
  private readonly measurementsErrorState = signal<string | null>(null);
  private readonly measurementsErrorStatusState = signal<number | null>(null);
  private readonly measurementsNextCursorState = signal<string | null>(null);

  private readonly conditionsState = signal<readonly Condition[]>([]);
  private readonly conditionsLoadingState = signal(false);
  private readonly conditionsErrorState = signal<string | null>(null);
  private readonly conditionsErrorStatusState = signal<number | null>(null);
  private readonly conditionsNextCursorState = signal<string | null>(null);

  private readonly allergiesState = signal<readonly Allergy[]>([]);
  private readonly allergiesLoadingState = signal(false);
  private readonly allergiesErrorState = signal<string | null>(null);
  private readonly allergiesErrorStatusState = signal<number | null>(null);
  private readonly allergiesNextCursorState = signal<string | null>(null);

  private readonly trendState = signal<readonly TrendPoint[]>([]);
  private readonly trendLoadingState = signal(false);
  private readonly trendErrorState = signal<string | null>(null);

  readonly measurements = this.measurementsState.asReadonly();
  readonly measurementsLoading = this.measurementsLoadingState.asReadonly();
  readonly measurementsError = this.measurementsErrorState.asReadonly();
  readonly measurementsErrorStatus = this.measurementsErrorStatusState.asReadonly();
  readonly measurementsNextCursor = this.measurementsNextCursorState.asReadonly();

  readonly conditions = this.conditionsState.asReadonly();
  readonly conditionsLoading = this.conditionsLoadingState.asReadonly();
  readonly conditionsError = this.conditionsErrorState.asReadonly();
  readonly conditionsErrorStatus = this.conditionsErrorStatusState.asReadonly();
  readonly conditionsNextCursor = this.conditionsNextCursorState.asReadonly();

  readonly allergies = this.allergiesState.asReadonly();
  readonly allergiesLoading = this.allergiesLoadingState.asReadonly();
  readonly allergiesError = this.allergiesErrorState.asReadonly();
  readonly allergiesErrorStatus = this.allergiesErrorStatusState.asReadonly();
  readonly allergiesNextCursor = this.allergiesNextCursorState.asReadonly();

  readonly trendPoints = this.trendState.asReadonly();
  readonly trendLoading = this.trendLoadingState.asReadonly();
  readonly trendError = this.trendErrorState.asReadonly();

  private measurementsToken = 0;
  private conditionsToken = 0;
  private allergiesToken = 0;
  private trendToken = 0;

  /** Drops every cached collection, e.g. after a workspace or profile switch. */
  reset(): void {
    this.measurementsToken += 1;
    this.conditionsToken += 1;
    this.allergiesToken += 1;
    this.trendToken += 1;

    this.measurementsState.set([]);
    this.measurementsLoadingState.set(false);
    this.measurementsErrorState.set(null);
    this.measurementsErrorStatusState.set(null);
    this.measurementsNextCursorState.set(null);

    this.conditionsState.set([]);
    this.conditionsLoadingState.set(false);
    this.conditionsErrorState.set(null);
    this.conditionsErrorStatusState.set(null);
    this.conditionsNextCursorState.set(null);

    this.allergiesState.set([]);
    this.allergiesLoadingState.set(false);
    this.allergiesErrorState.set(null);
    this.allergiesErrorStatusState.set(null);
    this.allergiesNextCursorState.set(null);

    this.trendState.set([]);
    this.trendLoadingState.set(false);
    this.trendErrorState.set(null);
  }

  async listMeasurements(
    profileId: string,
    filters: MeasurementFilters = {},
    append = false,
  ): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      return;
    }

    const token = ++this.measurementsToken;

    this.measurementsLoadingState.set(true);
    this.measurementsErrorState.set(null);
    this.measurementsErrorStatusState.set(null);

    if (!append) {
      this.measurementsState.set([]);
      this.measurementsNextCursorState.set(null);
    }

    const url = withQuery(this.profilePath(workspaceId, profileId, 'measurements'), [
      ['type', filters.type ?? null],
      ['from', filters.from ?? null],
      ['to', filters.to ?? null],
      ['limit', filters.limit === undefined ? null : String(filters.limit)],
      ['cursor', filters.cursor ?? null],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.measurementsToken) {
        return;
      }

      const page = parseMeasurementPage(response);
      this.measurementsState.update((current) =>
        append ? mergeById(current, page.items) : page.items,
      );
      this.measurementsNextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.measurementsToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.measurementsState.set([]);
        this.measurementsNextCursorState.set(null);
      }

      this.measurementsErrorStatusState.set(status);
      this.measurementsErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.measurementsToken) {
        this.measurementsLoadingState.set(false);
      }
    }
  }

  async createMeasurement(profileId: string, body: MeasurementCreateBody): Promise<Measurement> {
    const workspaceId = this.requireWorkspace();

    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        this.profilePath(workspaceId, profileId, 'measurements'),
        body,
      ),
    );
    const created = parseMeasurement(response.data);

    if (created === null) {
      throw new Error('invalid_measurement_payload');
    }

    this.measurementsState.update((current) => [created, ...current]);

    return created;
  }

  async trend(
    profileId: string,
    type: string,
    from: string | null = null,
    to: string | null = null,
  ): Promise<readonly TrendPoint[]> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      return [];
    }

    const token = ++this.trendToken;

    this.trendLoadingState.set(true);
    this.trendErrorState.set(null);

    const url = withQuery(this.profilePath(workspaceId, profileId, 'measurements/trend'), [
      ['type', type],
      ['from', from],
      ['to', to],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.trendToken) {
        return [];
      }

      const points = parseTrendPoints(response.data);
      this.trendState.set(points);

      return points;
    } catch (error) {
      if (token === this.trendToken) {
        this.trendState.set([]);
        this.trendErrorState.set(toUserMessage(error));
      }

      return [];
    } finally {
      if (token === this.trendToken) {
        this.trendLoadingState.set(false);
      }
    }
  }

  async listConditions(profileId: string, filters: ConditionFilters = {}): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      return;
    }

    const token = ++this.conditionsToken;

    this.conditionsLoadingState.set(true);
    this.conditionsErrorState.set(null);
    this.conditionsErrorStatusState.set(null);

    const url = withQuery(this.profilePath(workspaceId, profileId, 'conditions'), [
      ['status', filters.status ?? null],
      ['limit', filters.limit === undefined ? null : String(filters.limit)],
      ['cursor', filters.cursor ?? null],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.conditionsToken) {
        return;
      }

      const page = parseConditionPage(response);
      this.conditionsState.set(page.items);
      this.conditionsNextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.conditionsToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.conditionsState.set([]);
        this.conditionsNextCursorState.set(null);
      }

      this.conditionsErrorStatusState.set(status);
      this.conditionsErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.conditionsToken) {
        this.conditionsLoadingState.set(false);
      }
    }
  }

  async createCondition(profileId: string, body: ConditionWriteBody): Promise<Condition> {
    const workspaceId = this.requireWorkspace();

    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(this.profilePath(workspaceId, profileId, 'conditions'), body),
    );
    const created = parseCondition(response.data);

    if (created === null) {
      throw new Error('invalid_condition_payload');
    }

    this.conditionsState.update((current) => [created, ...current]);

    return created;
  }

  async updateCondition(
    profileId: string,
    conditionId: string,
    version: number,
    body: ConditionWriteBody,
  ): Promise<Condition> {
    const workspaceId = this.requireWorkspace();

    const response = await firstValueFrom(
      this.http.patch<EntityEnvelope>(
        `${this.profilePath(workspaceId, profileId, 'conditions')}/${encodeURIComponent(
          conditionId,
        )}`,
        body,
        { headers: { 'If-Match': String(version) } },
      ),
    );
    const updated = parseCondition(response.data);

    if (updated === null) {
      throw new Error('invalid_condition_payload');
    }

    this.conditionsState.update((current) =>
      current.map((item) => (item.id === updated.id ? updated : item)),
    );

    return updated;
  }

  async listAllergies(profileId: string, filters: AllergyFilters = {}): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      return;
    }

    const token = ++this.allergiesToken;

    this.allergiesLoadingState.set(true);
    this.allergiesErrorState.set(null);
    this.allergiesErrorStatusState.set(null);

    const url = withQuery(this.profilePath(workspaceId, profileId, 'allergies'), [
      ['severity', filters.severity ?? null],
      ['limit', filters.limit === undefined ? null : String(filters.limit)],
      ['cursor', filters.cursor ?? null],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.allergiesToken) {
        return;
      }

      const page = parseAllergyPage(response);
      this.allergiesState.set(page.items);
      this.allergiesNextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.allergiesToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.allergiesState.set([]);
        this.allergiesNextCursorState.set(null);
      }

      this.allergiesErrorStatusState.set(status);
      this.allergiesErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.allergiesToken) {
        this.allergiesLoadingState.set(false);
      }
    }
  }

  /**
   * Creates the first version of an allergy, or patches an existing row with
   * its current `If-Match` version. The catalog has no `allergy.create`, so
   * both operations are authorized by `allergy.update`.
   */
  async upsertAllergy(
    profileId: string,
    body: AllergyWriteBody,
    existing: Allergy | null = null,
  ): Promise<Allergy> {
    const workspaceId = this.requireWorkspace();
    const collectionUrl = this.profilePath(workspaceId, profileId, 'allergies');

    if (existing === null) {
      const response = await firstValueFrom(this.http.post<EntityEnvelope>(collectionUrl, body));
      const created = parseAllergy(response.data);

      if (created === null) {
        throw new Error('invalid_allergy_payload');
      }

      this.allergiesState.update((current) => [created, ...current]);

      return created;
    }

    const response = await firstValueFrom(
      this.http.patch<EntityEnvelope>(`${collectionUrl}/${encodeURIComponent(existing.id)}`, body, {
        headers: { 'If-Match': String(existing.version) },
      }),
    );
    const updated = parseAllergy(response.data);

    if (updated === null) {
      throw new Error('invalid_allergy_payload');
    }

    this.allergiesState.update((current) =>
      current.map((item) => (item.id === updated.id ? updated : item)),
    );

    return updated;
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
