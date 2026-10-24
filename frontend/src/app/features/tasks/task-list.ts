import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { CareTaskService } from '../../core/caretask/caretask.service';
import {
  CARE_TASK_STATUSES,
  CARE_TASK_TIERS,
  CareTask,
  CareTaskFilters,
  groupTasks,
  isCareTaskStatus,
  isCareTaskTier,
  taskPriorityLabel,
  taskStatusLabel,
  taskTierLabel,
} from '../../core/caretask/caretask.models';
import { formatThaiDateTime } from '../../core/health-record/health-record.format';

@Component({
  selector: 'app-task-list',
  imports: [RouterLink],
  templateUrl: './task-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TaskList {
  private readonly careTasks = inject(CareTaskService);

  /**
   * The page owns the workspace gate; the list only queries once a workspace is
   * known. A switch therefore re-runs the first page instead of keeping rows
   * from the previous family.
   */
  readonly workspaceId = input<string | null>(null);

  protected readonly tasks = this.careTasks.tasks;
  protected readonly loading = this.careTasks.tasksLoading;
  protected readonly error = this.careTasks.tasksError;
  protected readonly nextCursor = this.careTasks.tasksNextCursor;

  protected readonly status = signal<string>('');
  protected readonly tier = signal<string>('');
  protected readonly assigneeMe = signal(false);
  protected readonly overdue = signal(false);
  protected readonly skeletons = [1, 2, 3];

  private readonly now = signal(Date.now());

  protected readonly statusOptions = CARE_TASK_STATUSES.map((status) => ({
    value: status,
    label: taskStatusLabel(status),
  }));

  protected readonly tierOptions = CARE_TASK_TIERS.map((tier) => ({
    value: tier,
    label: taskTierLabel(tier),
  }));

  protected readonly groups = computed(() => groupTasks(this.tasks(), new Date(this.now())));

  constructor() {
    effect(() => {
      const workspaceId = this.workspaceId();

      if (workspaceId === null || workspaceId === '') {
        return;
      }

      untracked(() => {
        void this.load();
      });
    });
  }

  protected load(append = false): Promise<void> {
    this.now.set(Date.now());

    return this.careTasks.list(this.currentFilters(), append);
  }

  protected applyFilters(): void {
    void this.load();
  }

  protected clearFilters(): void {
    this.status.set('');
    this.tier.set('');
    this.assigneeMe.set(false);
    this.overdue.set(false);
    void this.load();
  }

  protected changeStatus(event: Event): void {
    const value = this.readValue(event);

    this.status.set(isCareTaskStatus(value) ? value : '');
    this.applyFilters();
  }

  protected changeTier(event: Event): void {
    const value = this.readValue(event);

    this.tier.set(isCareTaskTier(value) ? value : '');
    this.applyFilters();
  }

  protected toggleAssigneeMe(event: Event): void {
    this.assigneeMe.set(this.readChecked(event));
    this.applyFilters();
  }

  protected toggleOverdue(event: Event): void {
    this.overdue.set(this.readChecked(event));
    this.applyFilters();
  }

  protected loadMore(): void {
    const cursor = this.nextCursor();

    if (cursor === null) {
      return;
    }

    void this.careTasks.list({ ...this.currentFilters(), cursor }, true);
  }

  protected dueLabel(task: CareTask): string {
    return formatThaiDateTime(task.due_at);
  }

  protected statusLabel(task: CareTask): string {
    return taskStatusLabel(task.status);
  }

  protected priorityLabel(task: CareTask): string {
    return taskPriorityLabel(task.priority);
  }

  protected tierLabel(task: CareTask): string | null {
    return task.kind === 'full' ? taskTierLabel(task.tier) : null;
  }

  protected canReadDetails(task: CareTask): boolean {
    return task.kind === 'full';
  }

  private currentFilters(): CareTaskFilters {
    const status = this.status();
    const tier = this.tier();

    return {
      status: isCareTaskStatus(status) ? status : null,
      tier: isCareTaskTier(tier) ? tier : null,
      assignee: this.assigneeMe() ? 'me' : null,
      overdue: this.overdue(),
      limit: 20,
    };
  }

  private readValue(event: Event): string {
    const target = event.target;

    return target instanceof HTMLSelectElement ? target.value : '';
  }

  private readChecked(event: Event): boolean {
    const target = event.target;

    return target instanceof HTMLInputElement ? target.checked : false;
  }
}
