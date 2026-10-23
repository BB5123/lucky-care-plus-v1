import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { toUserMessage } from '../api/problem-details';
import { MembershipSummary } from '../auth/auth.models';
import { AuthService } from '../auth/auth.service';

const WORKSPACE_STORAGE_KEY = 'luckycare.workspace';

/** One row of `GET /workspaces/{workspace}/members`. */
export interface WorkspaceMember {
  readonly user_id: number;
  readonly name: string | null;
  readonly role: string;
  readonly status: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readMember(value: unknown): WorkspaceMember | null {
  if (!isRecord(value)) {
    return null;
  }

  const userId = value['user_id'];

  if (typeof userId !== 'number' || !Number.isFinite(userId)) {
    return null;
  }

  return {
    user_id: userId,
    name: typeof value['name'] === 'string' ? value['name'] : null,
    role: typeof value['role'] === 'string' ? value['role'] : 'member',
    status: typeof value['status'] === 'string' ? value['status'] : 'active',
  };
}

function readStoredWorkspaceId(): string | null {
  try {
    return window.localStorage.getItem(WORKSPACE_STORAGE_KEY);
  } catch {
    return null;
  }
}

function writeStoredWorkspaceId(workspaceId: string): void {
  try {
    window.localStorage.setItem(WORKSPACE_STORAGE_KEY, workspaceId);
  } catch {
    // The in-memory selection still works when storage is unavailable.
  }
}

function removeStoredWorkspaceId(): void {
  try {
    window.localStorage.removeItem(WORKSPACE_STORAGE_KEY);
  } catch {
    // Nothing to clean up when storage is unavailable.
  }
}

/**
 * The authenticated user's workspace context.
 *
 * The server decides which workspaces a user may enter; this service only
 * remembers the user's local choice. `status === 'active'` is the only status
 * the dashboard can open, so a suspended membership never becomes the default.
 */
@Injectable({ providedIn: 'root' })
export class WorkspaceService {
  private readonly auth = inject(AuthService);
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  private readonly workspacesState = signal<readonly MembershipSummary[]>([]);
  private readonly currentState = signal<MembershipSummary | null>(null);
  private readonly loadingState = signal(false);
  private readonly membersState = signal<readonly WorkspaceMember[]>([]);
  private readonly membersLoadingState = signal(false);
  private readonly membersErrorState = signal<string | null>(null);

  private inFlight: Promise<void> | null = null;
  private membersInFlight: Promise<void> | null = null;
  private membersWorkspaceId: string | null = null;
  private loadedForUserId: string | null = null;

  readonly workspaces = this.workspacesState.asReadonly();
  readonly activeWorkspaces = computed(() =>
    this.workspacesState().filter((item) => item.status === 'active'),
  );
  readonly currentWorkspace = this.currentState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly hasWorkspace = computed(() => this.currentState() !== null);

  /**
   * Active members of the current workspace, loaded only when a screen needs
   * them (the care-task assign picker). Unknown members are never invented.
   */
  readonly members = this.membersState.asReadonly();
  readonly membersLoading = this.membersLoadingState.asReadonly();
  readonly membersError = this.membersErrorState.asReadonly();

  async loadMembers(): Promise<void> {
    const workspaceId = this.currentState()?.workspace_id ?? null;

    if (workspaceId === null) {
      this.membersState.set([]);
      return;
    }

    if (this.membersWorkspaceId === workspaceId) {
      return;
    }

    if (this.membersInFlight !== null) {
      return this.membersInFlight;
    }

    this.membersInFlight = this.fetchMembers(workspaceId);

    return this.membersInFlight;
  }

  /**
   * Hydrates the workspace list after authentication.
   *
   * `GET /me` already returns `data.workspaces`, so when the auth service has
   * the memberships in memory no extra request is made. Concurrent callers
   * (shell + dashboard on boot) share the same in-flight request.
   */
  load(): Promise<void> {
    if (this.inFlight !== null) {
      return this.inFlight;
    }

    const userId = this.auth.user()?.id ?? null;
    const cached = this.auth.memberships();

    // The same identity already loaded once (including a zero-workspace user):
    // re-hydrate from memory instead of asking `/me` again.
    if (userId !== null && this.loadedForUserId === userId) {
      this.hydrate(cached);
      return Promise.resolve();
    }

    if (cached.length > 0) {
      this.hydrate(cached);
      this.loadedForUserId = userId;
      return Promise.resolve();
    }

    this.inFlight = this.fetch();

    return this.inFlight;
  }

  /**
   * Switches the active workspace. Unknown ids are ignored: the client never
   * invents a workspace that the server did not return.
   */
  select(workspaceId: string): boolean {
    const next = this.workspacesState().find((item) => item.workspace_id === workspaceId) ?? null;

    if (next === null || next.status !== 'active') {
      return false;
    }

    if (this.currentState()?.workspace_id === workspaceId) {
      return true;
    }

    this.currentState.set(next);
    writeStoredWorkspaceId(workspaceId);
    this.clearMembers();

    return true;
  }

  reset(): void {
    this.workspacesState.set([]);
    this.currentState.set(null);
    this.loadingState.set(false);
    this.inFlight = null;
    this.loadedForUserId = null;
    this.clearMembers();
    removeStoredWorkspaceId();
  }

  private async fetchMembers(workspaceId: string): Promise<void> {
    this.membersLoadingState.set(true);
    this.membersErrorState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<{ readonly data?: unknown }>(
          `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/members`,
        ),
      );
      const rows = Array.isArray(response.data) ? response.data : [];

      this.membersState.set(
        rows.map((row) => readMember(row)).filter((row): row is WorkspaceMember => row !== null),
      );
      this.membersWorkspaceId = workspaceId;
    } catch (error) {
      this.membersState.set([]);
      this.membersErrorState.set(toUserMessage(error));
    } finally {
      this.membersLoadingState.set(false);
      this.membersInFlight = null;
    }
  }

  private clearMembers(): void {
    this.membersInFlight = null;
    this.membersWorkspaceId = null;
    this.membersState.set([]);
    this.membersLoadingState.set(false);
    this.membersErrorState.set(null);
  }

  private async fetch(): Promise<void> {
    this.loadingState.set(true);

    try {
      const me = await firstValueFrom(this.auth.refreshMe());
      this.loadedForUserId = me.user.id;
      this.hydrate(me.workspaces);
    } finally {
      this.loadingState.set(false);
      this.inFlight = null;
    }
  }

  private hydrate(memberships: readonly MembershipSummary[]): void {
    this.clearMembers();
    this.workspacesState.set(memberships);

    const active = memberships.filter((item) => item.status === 'active');
    const storedId = readStoredWorkspaceId();
    const chosen = active.find((item) => item.workspace_id === storedId) ?? active[0] ?? null;

    this.currentState.set(chosen);

    if (chosen !== null) {
      writeStoredWorkspaceId(chosen.workspace_id);
    } else {
      removeStoredWorkspaceId();
    }
  }
}
