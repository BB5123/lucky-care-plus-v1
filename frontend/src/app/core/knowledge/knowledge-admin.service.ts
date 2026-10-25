import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { FieldErrors, httpStatus, readFieldErrors, toUserMessage } from '../api/problem-details';
import { AuthService } from '../auth/auth.service';
import {
  AdminKnowledgeSource,
  KnowledgeSourceStatus,
  parseAdminKnowledgeSource,
  parseAdminKnowledgeSources,
} from './knowledge-admin.models';

export type KnowledgeAdminErrorKind = 'forbidden' | 'unauthorized' | 'validation' | 'generic';

/**
 * A typed failure from the platform knowledge API.
 *
 * The UI needs to tell four cases apart: a revoked capability (403), an expired
 * session (401), invalid input (422 with per-field sentences) and everything
 * else. The server sentence stays in `message`; the field map is only read for
 * `validation`.
 */
export class KnowledgeAdminError extends Error {
  constructor(
    readonly kind: KnowledgeAdminErrorKind,
    readonly status: number | null,
    readonly fieldErrors: FieldErrors = {},
    message?: string,
  ) {
    super(message ?? 'เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่อีกครั้ง');
    this.name = 'KnowledgeAdminError';
  }
}

export interface KnowledgeSourceQuery {
  readonly status?: KnowledgeSourceStatus | null;
  readonly q?: string | null;
}

export interface ReviewKnowledgeSourceInput {
  readonly reviewer: string;
  readonly build?: boolean;
}

interface Envelope {
  readonly data?: unknown;
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

function toKnowledgeAdminError(error: unknown): KnowledgeAdminError {
  if (error instanceof KnowledgeAdminError) {
    return error;
  }

  const status = httpStatus(error);
  const message = toUserMessage(error);

  if (status === 403) {
    return new KnowledgeAdminError('forbidden', status, {}, message);
  }

  if (status === 401) {
    return new KnowledgeAdminError('unauthorized', status, {}, message);
  }

  if (status === 422) {
    return new KnowledgeAdminError('validation', status, readFieldErrors(error), message);
  }

  return new KnowledgeAdminError('generic', status, {}, message);
}

/**
 * Client for the platform knowledge-corpus administration API.
 *
 * The list response is the whole result set for the current filters, so the
 * page keeps the last unfiltered count as `total` and renders "N of M". A
 * review never rewrites the corpus locally: the server response replaces the
 * one row, in place.
 */
@Injectable({ providedIn: 'root' })
export class KnowledgeAdminService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly auth = inject(AuthService);

  private readonly sourcesState = signal<readonly AdminKnowledgeSource[]>([]);
  private readonly totalState = signal(0);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private readonly errorKindState = signal<KnowledgeAdminErrorKind | null>(null);

  readonly sources = this.sourcesState.asReadonly();
  readonly total = this.totalState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();
  readonly errorKind = this.errorKindState.asReadonly();

  /** From the `/me` cache: platform capability never comes from a role. */
  readonly isPlatformAdmin = computed(() => this.auth.isPlatformAdmin());

  private listToken = 0;

  reset(): void {
    this.listToken += 1;
    this.sourcesState.set([]);
    this.totalState.set(0);
    this.loadingState.set(false);
    this.errorState.set(null);
    this.errorKindState.set(null);
  }

  async list(query: KnowledgeSourceQuery = {}): Promise<readonly AdminKnowledgeSource[]> {
    const token = ++this.listToken;

    this.loadingState.set(true);
    this.errorState.set(null);
    this.errorKindState.set(null);

    const status = query.status ?? null;
    const q = query.q?.trim() ?? '';

    try {
      const response = await firstValueFrom(
        this.http.get<Envelope>(
          withQuery(`${this.baseUrl}/admin/knowledge/sources`, [
            ['status', status],
            ['q', q],
          ]),
        ),
      );

      if (token !== this.listToken) {
        return this.sourcesState();
      }

      const sources = parseAdminKnowledgeSources(response?.data);

      if (sources === null) {
        throw new KnowledgeAdminError('generic', null, {}, 'ข้อมูลแหล่งความรู้ที่ได้รับไม่ถูกต้อง');
      }

      this.sourcesState.set(sources);

      // Only an unfiltered read knows the corpus size; a filtered read must not
      // shrink M to the number of visible rows.
      if (status === null && q === '') {
        this.totalState.set(sources.length);
      }

      return sources;
    } catch (error) {
      if (token !== this.listToken) {
        return this.sourcesState();
      }

      const typed = toKnowledgeAdminError(error);

      this.sourcesState.set([]);
      this.errorState.set(typed.message);
      this.errorKindState.set(typed.kind);

      throw typed;
    } finally {
      if (token === this.listToken) {
        this.loadingState.set(false);
      }
    }
  }

  async review(id: string, input: ReviewKnowledgeSourceInput): Promise<AdminKnowledgeSource> {
    try {
      const response = await firstValueFrom(
        this.http.post<Envelope>(
          `${this.baseUrl}/admin/knowledge/sources/${encodeURIComponent(id)}/review`,
          {
            reviewer: input.reviewer.trim(),
            build: input.build ?? true,
          },
        ),
      );

      const source = parseAdminKnowledgeSource(response?.data);

      if (source === null) {
        throw new KnowledgeAdminError('generic', null, {}, 'ข้อมูลแหล่งความรู้ที่ได้รับไม่ถูกต้อง');
      }

      return source;
    } catch (error) {
      throw toKnowledgeAdminError(error);
    }
  }

  /** Replaces one row after a successful review, without re-sorting or refetching. */
  applyReviewed(source: AdminKnowledgeSource): void {
    this.sourcesState.update((rows) => rows.map((row) => (row.id === source.id ? source : row)));
  }
}
