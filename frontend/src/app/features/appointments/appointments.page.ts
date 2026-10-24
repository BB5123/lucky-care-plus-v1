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
import { ActivatedRoute } from '@angular/router';
import { Subscription } from 'rxjs';
import { toUserMessage } from '../../core/api/problem-details';
import { Appointment } from '../../core/appointments/appointment.models';
import { AppointmentService } from '../../core/appointments/appointment.service';
import { DashboardService } from '../../core/dashboard/dashboard.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { AppointmentForm } from './appointment-form';
import { AppointmentList } from './appointment-list';

/**
 * Appointments landing page.
 *
 * The profile selector is fed by the dashboard family payload, the same source
 * the health and timeline screens use. The list itself keeps appointments in
 * two groups: upcoming (soonest first) and past.
 */
@Component({
  selector: 'app-appointments-page',
  imports: [AppointmentForm, AppointmentList],
  templateUrl: './appointments.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentsPage {
  private readonly route = inject(ActivatedRoute);
  private readonly workspace = inject(WorkspaceService);
  private readonly dashboard = inject(DashboardService);
  private readonly appointments = inject(AppointmentService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly hasWorkspace = this.workspace.hasWorkspace;

  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly selectedProfileId = signal<string | null>(null);
  protected readonly formOpen = signal(false);
  protected readonly announcement = signal<string | null>(null);

  protected readonly family = computed(() => {
    const payload = this.dashboard.dashboard();

    if (payload === null || !payload.family.visible) {
      return [];
    }

    return payload.family.members.filter((member) => member.profile_id !== '');
  });

  protected readonly selectedProfileName = computed(() => {
    const profileId = this.selectedProfileId();

    if (profileId === null) {
      return null;
    }

    return this.family().find((member) => member.profile_id === profileId)?.display_name ?? null;
  });

  private requestedProfileId: string | null = null;
  private routeSubscription: Subscription | null = null;
  private bootstrapped = false;
  private lastWorkspaceId: string | null = null;

  constructor() {
    this.routeSubscription = this.route.queryParamMap.subscribe((params) => {
      const profileId = params.get('profile_id');
      this.requestedProfileId = profileId !== null && profileId !== '' ? profileId : null;

      if (
        this.bootstrapped &&
        this.requestedProfileId !== null &&
        this.selectedProfileId() !== this.requestedProfileId
      ) {
        this.selectProfile(this.requestedProfileId);
      }
    });

    this.destroyRef.onDestroy(() => {
      this.routeSubscription?.unsubscribe();
    });

    effect(() => {
      const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

      if (!this.bootstrapped) {
        this.lastWorkspaceId = workspaceId;
        return;
      }

      if (workspaceId === this.lastWorkspaceId) {
        return;
      }

      this.lastWorkspaceId = workspaceId;

      untracked(() => {
        this.selectedProfileId.set(null);
        this.formOpen.set(false);
        this.announcement.set(null);
        this.appointments.reset();
        this.dashboard.invalidate();
        void this.initialize();
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
      this.bootstrapped = true;
      return;
    }

    this.lastWorkspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (!this.workspace.hasWorkspace()) {
      this.pageLoading.set(false);
      this.bootstrapped = true;
      return;
    }

    await this.dashboard.load(null);

    const members = this.family();
    const profileId = this.requestedProfileId ?? members[0]?.profile_id ?? null;

    this.selectedProfileId.set(profileId);
    this.pageLoading.set(false);
    this.bootstrapped = true;
  }

  protected selectProfile(profileId: string): void {
    if (this.selectedProfileId() === profileId) {
      return;
    }

    this.formOpen.set(false);
    this.announcement.set(null);
    this.appointments.reset();
    this.selectedProfileId.set(profileId);
  }

  protected openForm(): void {
    this.announcement.set(null);
    this.formOpen.set(true);
  }

  protected closeForm(): void {
    this.formOpen.set(false);
  }

  protected onSaved(saved: Appointment): void {
    this.formOpen.set(false);
    this.announce(`เพิ่มนัดหมายแล้ว: ${saved.title}`);
  }

  protected retry(): void {
    void this.initialize();
  }

  private announce(message: string): void {
    this.announcement.set('');
    queueMicrotask(() => this.announcement.set(message));
  }
}
