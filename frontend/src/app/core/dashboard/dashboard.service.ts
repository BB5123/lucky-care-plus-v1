import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { ApiEnvelope } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { DashboardPayload, parseDashboardPayload } from './dashboard.models';

/**
 * Read model for the first screen.
 *
 * There is deliberately no client-side authorization here: the server decides
 * which zones exist (`visible`) and returns only the fields the actor may read.
 * The client parses the payload, remembers which workspace/profile it belongs
 * to, and hides data from a previous workspace immediately after a switch.
 */
@Injectable({ providedIn: 'root' })
export class DashboardService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly dataState = signal<DashboardPayload | null>(null);
  private readonly loadedWorkspaceIdState = signal<string | null>(null);
  private readonly profileIdState = signal<string | null>(null);
  private readonly loadingState = signal(false);
  private readonly errorMessageState = signal<string | null>(null);
  private readonly errorStatusState = signal<number | null>(null);

  private requestToken = 0;

  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorMessageState.asReadonly();
  readonly errorStatus = this.errorStatusState.asReadonly();
  readonly profileId = this.profileIdState.asReadonly();

  /**
   * A payload is only readable while the active workspace is the one it was
   * loaded for. Switching workspace therefore invalidates the cached signal
   * without keeping stale data on screen.
   */
  readonly dashboard = computed(() => {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null || this.loadedWorkspaceIdState() !== workspaceId) {
      return null;
    }

    return this.dataState();
  });

  /**
   * Loads the aggregate for the active workspace. Passing a `profile_id`
   * narrows every zone to that person; `null` means "ทุกคน".
   */
  async load(profileId: string | null = null): Promise<void> {
    await this.request(profileId, false, true);
  }

  /**
   * Manual refresh: always bypasses the 30-second server cache and keeps the
   * previous payload on screen until the new one arrives.
   */
  async refresh(profileId: string | null = this.profileIdState()): Promise<void> {
    await this.request(profileId, true, false);
  }

  /**
   * Drops the local payload. Used when the workspace context is reset.
   */
  invalidate(): void {
    this.requestToken += 1;
    this.dataState.set(null);
    this.loadedWorkspaceIdState.set(null);
    this.profileIdState.set(null);
    this.loadingState.set(false);
    this.errorMessageState.set(null);
    this.errorStatusState.set(null);
  }

  private async request(
    profileId: string | null,
    bypassCache: boolean,
    clearData: boolean,
  ): Promise<void> {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null) {
      this.dataState.set(null);
      this.errorMessageState.set(null);
      this.errorStatusState.set(null);
      return;
    }

    const token = ++this.requestToken;

    this.loadingState.set(true);
    this.errorMessageState.set(null);
    this.errorStatusState.set(null);

    if (clearData) {
      this.dataState.set(null);
      this.loadedWorkspaceIdState.set(null);
    }

    try {
      const response = await firstValueFrom(
        this.http.get<ApiEnvelope<unknown>>(this.buildUrl(workspaceId, profileId, bypassCache)),
      );

      if (token !== this.requestToken) {
        return;
      }

      this.dataState.set(parseDashboardPayload(response.data));
      this.loadedWorkspaceIdState.set(workspaceId);
      this.profileIdState.set(profileId);
    } catch (error) {
      if (token !== this.requestToken) {
        return;
      }

      const status = error instanceof HttpErrorResponse ? error.status : null;

      // A revoked/forbidden workspace must not keep rendering the old payload.
      if (status === 403 || status === 404) {
        this.dataState.set(null);
        this.loadedWorkspaceIdState.set(null);
      }

      this.errorStatusState.set(status);
      this.errorMessageState.set(toUserMessage(error));
    } finally {
      if (token === this.requestToken) {
        this.loadingState.set(false);
      }
    }
  }

  private buildUrl(workspaceId: string, profileId: string | null, bypassCache: boolean): string {
    const params = new URLSearchParams();

    if (profileId !== null && profileId !== '') {
      params.set('profile_id', profileId);
    }

    if (bypassCache) {
      params.set('bypass_cache', '1');
    }

    const query = params.toString();
    const path = `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/dashboard`;

    return query === '' ? path : `${path}?${query}`;
  }
}
