import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import {
  NotificationDelivery,
  NotificationDeliveryFilters,
  NotificationPreferencePatch,
  NotificationPreferences,
  mergeNotificationDeliveries,
  parseNotificationDelivery,
  parseNotificationDeliveryPage,
  parseNotificationPreferences,
} from './notifications.models';

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

/**
 * Self-service notification state.
 *
 * Deliveries and preferences are user-owned (`/me` endpoints), so this service
 * never needs a workspace id. `markRead` is optimistic: the row changes before
 * the request starts and is restored exactly if the request fails.
 */
@Injectable({ providedIn: 'root' })
export class NotificationsService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  private readonly deliveriesState = signal<readonly NotificationDelivery[]>([]);
  private readonly deliveriesLoadingState = signal(false);
  private readonly deliveriesErrorState = signal<string | null>(null);
  private readonly deliveriesErrorStatusState = signal<number | null>(null);
  private readonly deliveriesNextCursorState = signal<string | null>(null);
  private readonly unreadCountState = signal(0);

  private readonly preferencesState = signal<NotificationPreferences | null>(null);
  private readonly preferencesLoadingState = signal(false);
  private readonly preferencesSavingState = signal(false);
  private readonly preferencesErrorState = signal<string | null>(null);

  readonly deliveries = this.deliveriesState.asReadonly();
  readonly deliveriesLoading = this.deliveriesLoadingState.asReadonly();
  readonly deliveriesError = this.deliveriesErrorState.asReadonly();
  readonly deliveriesErrorStatus = this.deliveriesErrorStatusState.asReadonly();
  readonly deliveriesNextCursor = this.deliveriesNextCursorState.asReadonly();
  readonly unreadCount = this.unreadCountState.asReadonly();

  readonly preferences = this.preferencesState.asReadonly();
  readonly preferencesLoading = this.preferencesLoadingState.asReadonly();
  readonly preferencesSaving = this.preferencesSavingState.asReadonly();
  readonly preferencesError = this.preferencesErrorState.asReadonly();

  private deliveriesToken = 0;
  private unreadToken = 0;
  private preferencesToken = 0;

  reset(): void {
    this.deliveriesToken += 1;
    this.unreadToken += 1;
    this.preferencesToken += 1;

    this.deliveriesState.set([]);
    this.deliveriesLoadingState.set(false);
    this.deliveriesErrorState.set(null);
    this.deliveriesErrorStatusState.set(null);
    this.deliveriesNextCursorState.set(null);
    this.unreadCountState.set(0);
    this.preferencesState.set(null);
    this.preferencesLoadingState.set(false);
    this.preferencesSavingState.set(false);
    this.preferencesErrorState.set(null);
  }

  async loadDeliveries(filters: NotificationDeliveryFilters = {}, append = false): Promise<void> {
    const token = ++this.deliveriesToken;

    this.deliveriesLoadingState.set(true);
    this.deliveriesErrorState.set(null);
    this.deliveriesErrorStatusState.set(null);

    if (!append) {
      this.deliveriesState.set([]);
      this.deliveriesNextCursorState.set(null);
    }

    const url = withQuery(`${this.baseUrl}/me/notifications/deliveries`, [
      ['status', filters.status ?? null],
      ['channel', filters.channel ?? null],
      ['unread', filters.unread === true ? 'true' : null],
      ['limit', filters.limit === undefined ? null : String(filters.limit)],
      ['cursor', filters.cursor ?? null],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.deliveriesToken) {
        return;
      }

      const page = parseNotificationDeliveryPage(response);

      this.deliveriesState.update((current) =>
        append ? mergeNotificationDeliveries(current, page.items) : page.items,
      );
      this.deliveriesNextCursorState.set(page.nextCursor);

      if (
        !append &&
        filters.status === undefined &&
        filters.channel === undefined &&
        filters.unread !== true
      ) {
        this.unreadCountState.set(
          page.items.filter((delivery) => delivery.read_at === null).length,
        );
      }
    } catch (error) {
      if (token !== this.deliveriesToken) {
        return;
      }

      const status = statusOf(error);

      if (status === 403 || status === 404) {
        this.deliveriesState.set([]);
        this.deliveriesNextCursorState.set(null);
      }

      this.deliveriesErrorStatusState.set(status);
      this.deliveriesErrorState.set(toUserMessage(error));
    } finally {
      if (token === this.deliveriesToken) {
        this.deliveriesLoadingState.set(false);
      }
    }
  }

  /**
   * Refreshes the shell badge without polling. The ledger has no `total`
   * field, so the unread page is capped at the API maximum (100) and its row
   * count is the best available snapshot.
   */
  async refreshUnreadCount(): Promise<void> {
    const token = ++this.unreadToken;
    const url = withQuery(`${this.baseUrl}/me/notifications/deliveries`, [
      ['unread', 'true'],
      ['limit', '100'],
    ]);

    try {
      const response = await firstValueFrom(this.http.get<CollectionEnvelope>(url));

      if (token !== this.unreadToken) {
        return;
      }

      const page = parseNotificationDeliveryPage(response);
      this.unreadCountState.set(page.items.length);
    } catch {
      // The badge keeps its previous value; the inbox surfaces the real error.
    }
  }

  /**
   * Marks one delivery read. Returns `false` after restoring the previous row
   * and count, so the caller can show a Thai retry message.
   */
  async markRead(id: string): Promise<boolean> {
    const target = this.deliveriesState().find((delivery) => delivery.id === id);

    if (target === undefined || target.read_at !== null) {
      return true;
    }

    const optimisticReadAt = new Date().toISOString();

    this.deliveriesState.update((current) =>
      current.map((delivery) =>
        delivery.id === id ? { ...delivery, read_at: optimisticReadAt } : delivery,
      ),
    );
    this.unreadCountState.update((count) => Math.max(0, count - 1));

    try {
      const response = await firstValueFrom(
        this.http.post<EntityEnvelope>(
          `${this.baseUrl}/me/notifications/${encodeURIComponent(id)}/read`,
          {},
        ),
      );
      const updated = parseNotificationDelivery(response.data);

      if (updated !== null) {
        this.deliveriesState.update((current) =>
          current.map((delivery) => (delivery.id === id ? updated : delivery)),
        );
      }

      return true;
    } catch {
      this.deliveriesState.update((current) =>
        current.map((delivery) => (delivery.id === id ? target : delivery)),
      );
      this.unreadCountState.update((count) => count + 1);

      return false;
    }
  }

  async loadPreferences(): Promise<boolean> {
    const token = ++this.preferencesToken;

    this.preferencesLoadingState.set(true);
    this.preferencesErrorState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<EntityEnvelope>(`${this.baseUrl}/me/notification-preferences`),
      );

      if (token !== this.preferencesToken) {
        return false;
      }

      const preferences = parseNotificationPreferences(response.data);

      if (preferences === null) {
        this.preferencesErrorState.set('ไม่สามารถอ่านการตั้งค่าการแจ้งเตือนได้');
        return false;
      }

      this.preferencesState.set(preferences);

      return true;
    } catch (error) {
      if (token === this.preferencesToken) {
        this.preferencesErrorState.set(toUserMessage(error));
      }

      return false;
    } finally {
      if (token === this.preferencesToken) {
        this.preferencesLoadingState.set(false);
      }
    }
  }

  async updatePreferences(patch: NotificationPreferencePatch): Promise<boolean> {
    this.preferencesSavingState.set(true);
    this.preferencesErrorState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.patch<EntityEnvelope>(`${this.baseUrl}/me/notification-preferences`, patch),
      );
      const preferences = parseNotificationPreferences(response.data);

      if (preferences === null) {
        this.preferencesErrorState.set('ไม่สามารถบันทึกการตั้งค่าการแจ้งเตือนได้');
        return false;
      }

      this.preferencesState.set(preferences);

      return true;
    } catch (error) {
      this.preferencesErrorState.set(toUserMessage(error));

      return false;
    } finally {
      this.preferencesSavingState.set(false);
    }
  }
}
