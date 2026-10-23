import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { toUserMessage } from '../../core/api/problem-details';
import { AuthService } from '../../core/auth/auth.service';
import { DashboardService } from '../../core/dashboard/dashboard.service';
import { ElderModeService } from '../../core/preferences/elder-mode.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { ElderFocus } from './zones/elder-focus/elder-focus';
import { FamilySwitcher } from './zones/family-switcher/family-switcher';
import { NextAppointment } from './zones/next-appointment/next-appointment';
import { PriorityBanner } from './zones/priority-banner/priority-banner';
import { TasksToday } from './zones/tasks-today/tasks-today';
import { VitalsSnapshot } from './zones/vitals-snapshot/vitals-snapshot';
import { formatRelativeThai } from './relative-time';

/**
 * First screen: workspace → aggregate → the five blueprint zones in order.
 *
 * All visibility decisions come from the server. The page only decides how to
 * present `visible: false` (render nothing), an unknown workspace (invite
 * empty state), and a 403/404 (a polite Thai empty state, never a raw error).
 */
@Component({
  selector: 'app-dashboard-page',
  imports: [
    RouterLink,
    FamilySwitcher,
    PriorityBanner,
    TasksToday,
    VitalsSnapshot,
    NextAppointment,
    ElderFocus,
  ],
  templateUrl: './dashboard.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardPage {
  private readonly auth = inject(AuthService);
  private readonly workspace = inject(WorkspaceService);
  private readonly dashboard = inject(DashboardService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly elderMode = inject(ElderModeService);

  protected readonly user = this.auth.user;
  protected readonly currentWorkspace = this.workspace.currentWorkspace;
  protected readonly hasWorkspace = this.workspace.hasWorkspace;

  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly refreshing = signal(false);
  protected readonly selectedProfileId = signal<string | null>(null);

  protected readonly data = this.dashboard.dashboard;
  protected readonly dashboardError = this.dashboard.error;
  protected readonly initialLoading = computed(
    () => this.dashboard.loading() && this.data() === null,
  );

  protected readonly unavailable = computed(() => {
    const status = this.dashboard.errorStatus();

    return status === 403 || status === 404;
  });

  protected readonly showDashboardError = computed(
    () => this.dashboardError() !== null && !this.unavailable(),
  );

  protected readonly personNames = computed<Readonly<Record<string, string>>>(() => {
    const data = this.data();
    const names: Record<string, string> = {};

    if (data === null || !data.family.visible) {
      return names;
    }

    for (const member of data.family.members) {
      names[member.person_id] = member.display_name;
    }

    return names;
  });

  private readonly now = signal(Date.now());

  protected readonly generatedLabel = computed(() =>
    formatRelativeThai(this.data()?.generated_at ?? null, this.now()),
  );

  private readonly bootstrapped = signal(false);
  private lastWorkspaceId: string | null = null;
  private clockTimer: ReturnType<typeof setInterval> | null = null;

  constructor() {
    this.clockTimer = setInterval(() => this.now.set(Date.now()), 30_000);

    this.destroyRef.onDestroy(() => {
      if (this.clockTimer !== null) {
        clearInterval(this.clockTimer);
      }
    });

    // A workspace switch in the shell must re-query and clear the old payload.
    effect(() => {
      const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

      if (!this.bootstrapped()) {
        this.lastWorkspaceId = workspaceId;
        return;
      }

      if (workspaceId === this.lastWorkspaceId) {
        return;
      }

      this.lastWorkspaceId = workspaceId;

      untracked(() => {
        this.selectedProfileId.set(null);

        if (workspaceId === null) {
          this.dashboard.invalidate();
          return;
        }

        void this.dashboard.load(null);
      });
    });

    void this.initialize();
  }

  protected async initialize(): Promise<void> {
    this.pageLoading.set(true);
    this.pageError.set(null);

    try {
      await this.workspace.load();
    } catch (error) {
      this.pageError.set(toUserMessage(error));
      this.pageLoading.set(false);
      this.bootstrapped.set(true);
      return;
    }

    this.lastWorkspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;
    this.pageLoading.set(false);

    if (this.workspace.hasWorkspace()) {
      await this.dashboard.load(this.selectedProfileId());
    }

    this.bootstrapped.set(true);
  }

  protected selectPerson(personId: string | null): void {
    if (this.selectedProfileId() === personId) {
      return;
    }

    this.selectedProfileId.set(personId);
    void this.dashboard.load(personId);
  }

  protected retry(): void {
    void this.dashboard.load(this.selectedProfileId());
  }

  protected async refresh(): Promise<void> {
    if (this.refreshing()) {
      return;
    }

    this.refreshing.set(true);

    try {
      await this.dashboard.refresh(this.selectedProfileId());
    } finally {
      this.refreshing.set(false);
    }
  }
}
