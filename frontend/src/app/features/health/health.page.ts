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
import { HealthRecordService } from '../../core/health-record/health-record.service';
import { MedicationService } from '../../core/medication/medication.service';
import { ProfilesService } from '../../core/profiles/profiles.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { AllergyList } from './allergies/allergy-list';
import { ConditionList } from './conditions/condition-list';
import { MeasurementList } from './measurements/measurement-list';
import { MedicationPlanList } from '../medication/medication-plan-list';

type HealthTab = 'measurements' | 'conditions' | 'allergies' | 'medications';

interface HealthTabDefinition {
  readonly id: HealthTab;
  readonly label: string;
}

@Component({
  selector: 'app-health-page',
  imports: [MeasurementList, ConditionList, AllergyList, MedicationPlanList],
  templateUrl: './health.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HealthPage {
  private readonly route = inject(ActivatedRoute);
  private readonly workspace = inject(WorkspaceService);
  private readonly profilesService = inject(ProfilesService);
  private readonly health = inject(HealthRecordService);
  private readonly medication = inject(MedicationService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly tabs: readonly HealthTabDefinition[] = [
    { id: 'measurements', label: 'ค่าที่วัด' },
    { id: 'conditions', label: 'โรคประจําตัว' },
    { id: 'allergies', label: 'การแพ้ยา' },
    { id: 'medications', label: 'ยา' },
  ];

  protected readonly activeTab = signal<HealthTab>('measurements');
  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly selectedProfileId = signal<string | null>(null);
  protected readonly announcement = signal<string | null>(null);

  protected readonly hasWorkspace = this.workspace.hasWorkspace;

  /**
   * The health-profile picker comes from `GET /workspaces/{w}/profiles`, which
   * is authorized per profile. The dashboard family card is deliberately not
   * used here: it needs `person.read` and would hide profiles a granted
   * caregiver may enter.
   */
  protected readonly profiles = this.profilesService.profiles;

  protected readonly profilesDenied = computed(() => {
    const status = this.profilesService.errorStatus();

    return status === 403 || status === 404;
  });

  protected readonly selectedProfileName = computed(() => {
    const profileId = this.selectedProfileId();

    if (profileId === null) {
      return null;
    }

    return this.profiles().find((profile) => profile.id === profileId)?.display_name ?? null;
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
        this.medication.reset();
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

    await this.profilesService.load();

    const error = this.profilesService.error();
    const status = this.profilesService.errorStatus();

    if (error !== null && status !== 403 && status !== 404) {
      this.pageError.set(error);
      this.pageLoading.set(false);
      this.bootstrapped = true;
      return;
    }

    const profileId = this.requestedProfileId ?? this.profilesService.currentProfile()?.id ?? null;

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
    this.medication.reset();
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
