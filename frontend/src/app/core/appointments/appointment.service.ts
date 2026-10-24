import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import {
  Appointment,
  AppointmentFilters,
  AppointmentPage,
  AppointmentUpdateBody,
  AppointmentWriteBody,
  parseAppointment,
  parseAppointmentPage,
} from './appointment.models';

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

function replaceById(
  current: readonly Appointment[],
  updated: Appointment,
): readonly Appointment[] {
  return current.map((item) => (item.id === updated.id ? updated : item));
}

/**
 * Read/write model for appointments.
 *
 * Every mutation sends `If-Match` with the version the screen was rendered
 * from, so a stale screen fails with 412 instead of overwriting another
 * caregiver's transition. The only exception is `reschedule()`, which is a
 * create (POST to the collection) carrying `rescheduled_from_id`: APT-003
 * requires a replacement row, and a PATCH on `scheduled_at` would erase the
 * move from history.
 *
 * Mutations let their error escape so the screen can render the server's Thai
 * domain sentence (409) or the reload action (412).
 */
@Injectable({ providedIn: 'root' })
export class AppointmentService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly appointmentsState = signal<readonly Appointment[]>([]);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private readonly errorStatusState = signal<number | null>(null);
  private readonly nextCursorState = signal<string | null>(null);

  private readonly detailState = signal<Appointment | null>(null);
  private readonly detailLoadingState = signal(false);
  private readonly detailErrorState = signal<string | null>(null);
  private readonly detailErrorStatusState = signal<number | null>(null);

  readonly appointments = this.appointmentsState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();
  readonly errorStatus = this.errorStatusState.asReadonly();
  readonly nextCursor = this.nextCursorState.asReadonly();

  readonly detail = this.detailState.asReadonly();
  readonly detailLoading = this.detailLoadingState.asReadonly();
  readonly detailError = this.detailErrorState.asReadonly();
  readonly detailErrorStatus = this.detailErrorStatusState.asReadonly();

  private listToken = 0;
  private detailToken = 0;

  reset(): void {
    this.listToken += 1;
    this.detailToken += 1;

    this.appointmentsState.set([]);
    this.loadingState.set(false);
    this.errorState.set(null);
    this.errorStatusState.set(null);
    this.nextCursorState.set(null);

    this.detailState.set(null);
    this.detailLoadingState.set(false);
    this.detailErrorState.set(null);
    this.detailErrorStatusState.set(null);
  }

  async load(profileId: string, filters: AppointmentFilters = {}, append = false): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '') {
      this.reset();
      return;
    }

    const token = ++this.listToken;

    this.loadingState.set(true);
    this.errorState.set(null);
    this.errorStatusState.set(null);

    if (!append) {
      this.appointmentsState.set([]);
      this.nextCursorState.set(null);
    }

    const url = withQuery(this.collectionPath(workspaceId, profileId), [
      ['status', filters.status ?? null],
      ['from', filters.from ?? null],
      ['to', filters.to ?? null],
      ['upcoming', filters.upcoming === true ? 'true' : null],
      ['limit', filters.limit === undefined ? null : String(filters.limit)],
      ['cursor', filters.cursor ?? null],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.listToken) {
        return;
      }

      const page: AppointmentPage = parseAppointmentPage(response);
      this.appointmentsState.update((current) => {
        if (!append) {
          return page.items;
        }

        const seen = new Set(current.map((item) => item.id));

        return [...current, ...page.items.filter((item) => !seen.has(item.id))];
      });
      this.nextCursorState.set(page.nextCursor);
    } catch (error) {
      if (token !== this.listToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.appointmentsState.set([]);
        this.nextCursorState.set(null);
      }

      this.errorStatusState.set(status);
      this.errorState.set(toUserMessage(error));
    } finally {
      if (token === this.listToken) {
        this.loadingState.set(false);
      }
    }
  }

  async loadDetail(profileId: string, appointmentId: string): Promise<void> {
    const workspaceId = this.workspaceId();

    if (workspaceId === null || profileId === '' || appointmentId === '') {
      return;
    }

    const token = ++this.detailToken;

    this.detailLoadingState.set(true);
    this.detailErrorState.set(null);
    this.detailErrorStatusState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<EntityEnvelope>(this.itemPath(workspaceId, profileId, appointmentId)),
      );

      if (token !== this.detailToken) {
        return;
      }

      const appointment = parseAppointment(response.data);

      if (appointment === null) {
        throw new Error('invalid_appointment_payload');
      }

      this.detailState.set(appointment);
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

  /** Creates a new appointment. No `If-Match`: the row does not exist yet. */
  async create(profileId: string, body: AppointmentWriteBody): Promise<Appointment> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(this.collectionPath(workspaceId, profileId), body),
    );
    const created = parseAppointment(response.data);

    if (created === null) {
      throw new Error('invalid_appointment_payload');
    }

    this.appointmentsState.update((current) => [created, ...current]);

    return created;
  }

  /** Edits fields of the row the screen was rendered from. */
  async update(
    profileId: string,
    appointment: Appointment,
    body: AppointmentUpdateBody,
  ): Promise<Appointment> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.patch<EntityEnvelope>(this.itemPath(workspaceId, profileId, appointment.id), body, {
        headers: { 'If-Match': String(appointment.version) },
      }),
    );
    const updated = parseAppointment(response.data);

    if (updated === null) {
      throw new Error('invalid_appointment_payload');
    }

    this.store(updated);

    return updated;
  }

  /**
   * APT-003: a reschedule is a new appointment linked to the one it replaces.
   * Sending `rescheduled_from_id` in a POST lets the server mark the source
   * `rescheduled`; editing `scheduled_at` through PATCH would erase the move.
   */
  async reschedule(
    profileId: string,
    source: Appointment,
    body: AppointmentWriteBody,
  ): Promise<Appointment> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(this.collectionPath(workspaceId, profileId), {
        ...body,
        rescheduled_from_id: source.id,
      }),
    );
    const created = parseAppointment(response.data);

    if (created === null) {
      throw new Error('invalid_appointment_payload');
    }

    this.appointmentsState.update((current) => [created, ...current]);
    this.detailState.set(created);

    return created;
  }

  async cancel(profileId: string, appointment: Appointment, reason: string): Promise<Appointment> {
    const workspaceId = this.requireWorkspace();
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        `${this.itemPath(workspaceId, profileId, appointment.id)}/cancel`,
        { reason },
        { headers: { 'If-Match': String(appointment.version) } },
      ),
    );
    const cancelled = parseAppointment(response.data);

    if (cancelled === null) {
      throw new Error('invalid_appointment_payload');
    }

    this.store(cancelled);

    return cancelled;
  }

  /**
   * Idempotent on the server (`follow_up_task_id`). The UI still guards a
   * double submit so a second spinner never appears while the first create is
   * in flight.
   */
  async markAttended(
    profileId: string,
    appointment: Appointment,
    attendedAt: string | null = null,
  ): Promise<Appointment> {
    const workspaceId = this.requireWorkspace();
    const body: Readonly<Record<string, string>> =
      attendedAt === null || attendedAt.trim() === '' ? {} : { attended_at: attendedAt };
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        `${this.itemPath(workspaceId, profileId, appointment.id)}/attended`,
        body,
        { headers: { 'If-Match': String(appointment.version) } },
      ),
    );
    const attended = parseAppointment(response.data);

    if (attended === null) {
      throw new Error('invalid_appointment_payload');
    }

    this.store(attended);

    return attended;
  }

  /**
   * Toggles one checklist item. The optimistic write paints the checkbox
   * immediately; a failure restores the previous row so the screen never lies
   * about what the server accepted.
   */
  async toggleDocument(
    profileId: string,
    appointment: Appointment,
    index: number,
    done: boolean,
  ): Promise<Appointment> {
    const workspaceId = this.requireWorkspace();
    const previous = this.detailState();
    const hadDetail = previous !== null && previous.id === appointment.id;

    this.detailState.set({
      ...appointment,
      prepared_documents: appointment.prepared_documents.map((document, position) =>
        position === index ? { ...document, done } : document,
      ),
    });

    try {
      const response = await firstValueFrom(
        this.http.patch<EntityEnvelope>(
          `${this.itemPath(workspaceId, profileId, appointment.id)}/documents`,
          { index, done },
          { headers: { 'If-Match': String(appointment.version) } },
        ),
      );
      const updated = parseAppointment(response.data);

      if (updated === null) {
        throw new Error('invalid_appointment_payload');
      }

      this.store(updated);

      return updated;
    } catch (error) {
      this.detailState.set(hadDetail ? previous : null);
      throw error;
    }
  }

  private store(updated: Appointment): void {
    this.detailState.set(updated);
    this.appointmentsState.update((current) => replaceById(current, updated));
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

  private collectionPath(workspaceId: string, profileId: string): string {
    return `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/profiles/${encodeURIComponent(profileId)}/appointments`;
  }

  private itemPath(workspaceId: string, profileId: string, appointmentId: string): string {
    return `${this.collectionPath(workspaceId, profileId)}/${encodeURIComponent(appointmentId)}`;
  }
}
