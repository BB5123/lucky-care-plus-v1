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
import { DashboardService } from '../../core/dashboard/dashboard.service';
import { HealthRecordService } from '../../core/health-record/health-record.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { AllergyList } from './allergies/allergy-list';
import { ConditionList } from './conditions/condition-list';
import { MeasurementList } from './measurements/measurement-list';

type HealthTab = 'measurements' | 'conditions' | 'allergies';

interface HealthTabDefinition {
  readonly id: HealthTab;
  readonly label: string;
}

@Component({
  selector: 'app-health-page',
  imports: [MeasurementList, ConditionList, AllergyList],
  templateUrl: './health.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HealthPage {
  private readonly route = inject(ActivatedRoute);
  private readonly workspace = inject(WorkspaceService);
  private readonly dashboard = inject(DashboardService);
  private readonly health = inject(HealthRecordService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly tabs: readonly HealthTabDefinition[] = [
    { id: 'measurements', label: 'ค่าที่วัด' },
    { id: 'conditions', label: 'โรคประจําตัว' },
    { id: 'allergies', label: 'การแพ้ยา' },
  ];

  protected readonly activeTab = signal<HealthTab>('measurements');
  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly selectedProfileId = signal<string | null>(null);
  protected readonly announcement = signal<string | null>(null);

  protected readonly hasWorkspace = this.workspace.hasWorkspace;

  /**
   * Reuses the dashboard's family card, which is the only place the client
   * learns `profile_id` values. A user who may read only one profile still gets
   * that single member; a deep link may pass `?profile_id=` when the family
   * card itself is not readable.
   */
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
        this.announcement.set(null);
        this.health.reset();
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

    this.announcement.set(null);
    this.health.reset();
    this.selectedProfileId.set(profileId);
  }

  protected selectTab(tab: HealthTab): void {
    if (this.activeTab() === tab) {
      return;
    }

    this.announcement.set(null);
    this.activeTab.set(tab);
  }

  protected retry(): void {
    void this.initialize();
  }

  protected onSaved(): void {
    // Clear first, then set on the next microtask: some screen readers ignore a
    // repeated identical live-region update after a second consecutive save.
    this.announcement.set('');
    queueMicrotask(() => this.announcement.set('บันทึกแล้ว'));
  }
}
