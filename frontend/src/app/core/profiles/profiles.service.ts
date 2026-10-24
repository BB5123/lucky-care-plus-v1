import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import { HealthProfileSummary, parseProfiles, selectCurrentProfile } from './profiles.models';

interface ProfilesEnvelope {
  readonly data?: unknown;
}

/**
 * Read model for the health-profile picker.
 *
 * The list is authorized per profile (`health_profile.read`), so this service
 * must never be replaced by the dashboard family card: that card is gated by
 * `person.read` and hides profiles a granted caregiver may still enter.
 *
 * A 403/404 is recorded as an error status and clears the list; the page can
 * render a permission explanation instead of retrying. An empty list is a
 * successful answer and therefore keeps the error signals clear.
 */
@Injectable({ providedIn: 'root' })
export class ProfilesService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  private readonly profilesState = signal<readonly HealthProfileSummary[]>([]);
  private readonly loadingState = signal(false);
  private readonly errorMessageState = signal<string | null>(null);
  private readonly errorStatusState = signal<number | null>(null);
  private readonly loadedWorkspaceIdState = signal<string | null>(null);

  private requestToken = 0;

  /**
   * Only profiles loaded for the active workspace are readable. A workspace
   * switch hides the previous family immediately, before the new list arrives.
   */
  readonly profiles = computed(() => {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null || this.loadedWorkspaceIdState() !== workspaceId) {
      return [];
    }

    return this.profilesState();
  });

  readonly currentProfile = computed<HealthProfileSummary | null>(() =>
    selectCurrentProfile(this.profiles()),
  );

  readonly hasProfile = computed(() => this.currentProfile() !== null);
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorMessageState.asReadonly();
  readonly errorStatus = this.errorStatusState.asReadonly();

  async load(): Promise<void> {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null) {
      this.reset();
      return;
    }

    const token = ++this.requestToken;

    this.loadingState.set(true);
    this.errorMessageState.set(null);
    this.errorStatusState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<ProfilesEnvelope>(
          `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/profiles`,
        ),
      );

      if (token !== this.requestToken) {
        return;
      }

      this.profilesState.set(parseProfiles(response.data));
      this.loadedWorkspaceIdState.set(workspaceId);
    } catch (error) {
      if (token !== this.requestToken) {
        return;
      }

      const status = error instanceof HttpErrorResponse ? error.status : null;

      this.profilesState.set([]);
      this.loadedWorkspaceIdState.set(workspaceId);
      this.errorStatusState.set(status);
      this.errorMessageState.set(toUserMessage(error));
    } finally {
      if (token === this.requestToken) {
        this.loadingState.set(false);
      }
    }
  }

  reset(): void {
    this.requestToken += 1;
    this.profilesState.set([]);
    this.loadedWorkspaceIdState.set(null);
    this.loadingState.set(false);
    this.errorMessageState.set(null);
    this.errorStatusState.set(null);
  }
}
