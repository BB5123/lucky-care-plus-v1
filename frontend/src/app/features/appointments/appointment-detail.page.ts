import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Subscription } from 'rxjs';
import { toUserMessage } from '../../core/api/problem-details';
import { AppointmentService } from '../../core/appointments/appointment.service';
import { ProfilesService } from '../../core/profiles/profiles.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { AppointmentDetail } from './appointment-detail';

/**
 * Route wrapper for the appointment detail.
 *
 * The profile is never guessed from the appointment id: it comes from
 * `?profile_id=` (the list always passes it) or, for a deep link, from the
 * per-profile authorized profile list. The detail component only receives
 * trusted ids.
 */
@Component({
  selector: 'app-appointment-detail-page',
  imports: [AppointmentDetail],
  templateUrl: './appointment-detail.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentDetailPage {
  private readonly route = inject(ActivatedRoute);
  private readonly workspace = inject(WorkspaceService);
  private readonly profilesService = inject(ProfilesService);
  private readonly appointments = inject(AppointmentService);
  private readonly destroyRef = inject(DestroyRef);

  readonly appointmentId = input.required<string>();

  protected readonly hasWorkspace = this.workspace.hasWorkspace;

  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly selectedProfileId = signal<string | null>(null);

  private requestedProfileId: string | null = null;
  private routeSubscription: Subscription | null = null;
  private bootstrapped = false;
  private lastWorkspaceId: string | null = null;

  constructor() {
    this.routeSubscription = this.route.queryParamMap.subscribe((params) => {
      const profileId = params.get('profile_id');
      this.requestedProfileId = profileId !== null && profileId !== '' ? profileId : null;

      if (this.bootstrapped && this.requestedProfileId !== null) {
        this.selectedProfileId.set(this.requestedProfileId);
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
        this.appointments.reset();
        this.profilesService.reset();
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

    let profileId = this.requestedProfileId;

    if (profileId === null) {
      await this.profilesService.load();
      profileId = this.profilesService.currentProfile()?.id ?? null;
    }

    this.selectedProfileId.set(profileId);
    this.pageLoading.set(false);
    this.bootstrapped = true;
  }

  protected retry(): void {
    void this.initialize();
  }
}
