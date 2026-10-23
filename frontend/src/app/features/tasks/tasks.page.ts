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
import { toUserMessage } from '../../core/api/problem-details';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { TaskList } from './task-list';

/**
 * Workspace gate for the care-task list.
 *
 * The list itself is server-filtered; this page only proves which workspace is
 * active so the list never queries with a stale or missing id.
 */
@Component({
  selector: 'app-tasks-page',
  imports: [TaskList],
  templateUrl: './tasks.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TasksPage {
  private readonly workspace = inject(WorkspaceService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly hasWorkspace = this.workspace.hasWorkspace;
  protected readonly workspaceId = computed(
    () => this.workspace.currentWorkspace()?.workspace_id ?? null,
  );

  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);

  private bootstrapped = false;
  private lastWorkspaceId: string | null = null;

  constructor() {
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
        void this.initialize();
      });
    });

    this.destroyRef.onDestroy(() => {
      this.lastWorkspaceId = null;
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
    this.pageLoading.set(false);
    this.bootstrapped = true;
  }

  protected retry(): void {
    void this.initialize();
  }
}
