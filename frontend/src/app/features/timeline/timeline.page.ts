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
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subscription } from 'rxjs';
import { toUserMessage } from '../../core/api/problem-details';
import { DashboardService } from '../../core/dashboard/dashboard.service';
import { formatThaiDateTime } from '../../core/health-record/health-record.format';
import { timelineSummaryLabel } from '../../core/timeline/timeline.copy';
import { TimelineEntry, TimelineFilters } from '../../core/timeline/timeline.models';
import { TimelineService } from '../../core/timeline/timeline.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';

interface TimelineTypeChip {
  readonly id: string;
  readonly label: string;
  readonly types: readonly string[];
}

interface TimelineRangeOption {
  readonly days: number;
  readonly label: string;
}

const DAY_IN_MS = 24 * 60 * 60 * 1000;

/**
 * Timeline screen.
 *
 * The profile selector is fed by the dashboard family payload, the same source
 * the health screens use. Every row is rendered from its translation key, and
 * a redacted row is deliberately inert: no link, no id and no metadata.
 */
@Component({
  selector: 'app-timeline-page',
  imports: [RouterLink],
  templateUrl: './timeline.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TimelinePage {
  private readonly route = inject(ActivatedRoute);
  private readonly workspace = inject(WorkspaceService);
  private readonly dashboard = inject(DashboardService);
  private readonly timeline = inject(TimelineService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly typeChips: readonly TimelineTypeChip[] = [
    { id: 'measurement', label: 'ค่าที่วัด', types: ['measurement'] },
    { id: 'condition', label: 'โรคประจําตัว', types: ['condition'] },
    { id: 'allergy', label: 'การแพ้ยา', types: ['allergy'] },
    {
      id: 'medication',
      label: 'ยา',
      types: ['medication_plan', 'medication_record'],
    },
    { id: 'task', label: 'งานดูแล', types: ['care_task'] },
    { id: 'appointment', label: 'นัดหมาย', types: ['appointment'] },
  ];

  protected readonly ranges: readonly TimelineRangeOption[] = [
    { days: 7, label: '7 วัน' },
    { days: 30, label: '30 วัน' },
    { days: 90, label: '90 วัน' },
  ];

  protected readonly items = this.timeline.items;
  protected readonly loading = this.timeline.loading;
  protected readonly timelineError = this.timeline.error;
  protected readonly nextCursor = this.timeline.nextCursor;
  protected readonly hasWorkspace = this.workspace.hasWorkspace;

  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly selectedProfileId = signal<string | null>(null);
  protected readonly selectedChipIds = signal<readonly string[]>([]);
  protected readonly rangeDays = signal(30);

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
        this.timeline.reset();
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

    if (profileId !== null) {
      await this.reload();
    }
  }

  protected selectProfile(profileId: string): void {
    if (this.selectedProfileId() === profileId) {
      return;
    }

    this.selectedProfileId.set(profileId);
    void this.reload();
  }

  protected isChipSelected(chipId: string): boolean {
    return this.selectedChipIds().includes(chipId);
  }

  protected toggleChip(chip: TimelineTypeChip): void {
    const current = this.selectedChipIds();
    const next = current.includes(chip.id)
      ? current.filter((id) => id !== chip.id)
      : [...current, chip.id];

    this.selectedChipIds.set(next);
    void this.reload();
  }

  protected clearTypeFilters(): void {
    if (this.selectedChipIds().length === 0) {
      return;
    }

    this.selectedChipIds.set([]);
    void this.reload();
  }

  protected selectRange(days: number): void {
    if (this.rangeDays() === days) {
      return;
    }

    this.rangeDays.set(days);
    void this.reload();
  }

  protected retry(): void {
    if (this.selectedProfileId() === null) {
      void this.initialize();
      return;
    }

    void this.reload();
  }

  protected loadMore(): void {
    const cursor = this.nextCursor();
    const profileId = this.selectedProfileId();

    if (cursor === null || profileId === null) {
      return;
    }

    void this.timeline.load(profileId, this.filters(cursor), true);
  }

  protected summaryLabel(entry: TimelineEntry): string {
    return timelineSummaryLabel(entry.summary_key);
  }

  protected occurredLabel(entry: TimelineEntry): string {
    return formatThaiDateTime(entry.occurred_at);
  }

  protected destination(entry: TimelineEntry): string | null {
    switch (entry.type) {
      case 'measurement':
      case 'condition':
      case 'allergy':
      case 'medication_plan':
      case 'medication_record':
        return '/health';
      case 'care_task':
        return '/tasks';
      case 'appointment':
        return '/appointments';
      default:
        return null;
    }
  }

  protected entryQuery(entry: TimelineEntry): Readonly<Record<string, string>> | null {
    const profileId = this.selectedProfileId();

    if (this.destination(entry) !== '/health' || profileId === null) {
      return null;
    }

    return { profile_id: profileId };
  }

  private async reload(): Promise<void> {
    const profileId = this.selectedProfileId();

    if (profileId === null) {
      this.timeline.reset();
      return;
    }

    await this.timeline.load(profileId, this.filters());
  }

  private filters(cursor: string | null = null): TimelineFilters {
    const { from, to } = this.rangeBounds();
    const types = this.selectedTypes();

    return {
      from,
      to,
      types: types.length > 0 ? types : null,
      cursor,
      limit: 20,
    };
  }

  private selectedTypes(): readonly string[] {
    const selected = this.selectedChipIds();
    const values = new Set<string>();

    for (const chip of this.typeChips) {
      if (!selected.includes(chip.id)) {
        continue;
      }

      for (const type of chip.types) {
        values.add(type);
      }
    }

    return [...values];
  }

  private rangeBounds(): { readonly from: string; readonly to: string } {
    const to = new Date();
    const from = new Date(to.getTime() - this.rangeDays() * DAY_IN_MS);

    return {
      from: from.toISOString().slice(0, 10),
      to: to.toISOString().slice(0, 10),
    };
  }
}
