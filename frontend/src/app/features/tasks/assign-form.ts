import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import { CareTask, CareTaskFull } from '../../core/caretask/caretask.models';
import { CareTaskService } from '../../core/caretask/caretask.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';

interface RoleOption {
  readonly value: string;
  readonly label: string;
}

const ROLE_OPTIONS: readonly RoleOption[] = [
  { value: 'primary', label: 'ผู้รับผิดชอบหลัก' },
  { value: 'assistant', label: 'ผู้ช่วย' },
  { value: 'verifier', label: 'ผู้ตรวจรับ' },
];

/**
 * Primary assignment of an existing task.
 *
 * The picker only offers active workspace members returned by the server; a
 * stale task version is answered with 412 and the screen asks for a reload
 * instead of assigning against the wrong version.
 */
@Component({
  selector: 'app-assign-form',
  imports: [ReactiveFormsModule],
  templateUrl: './assign-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AssignForm {
  private readonly careTasks = inject(CareTaskService);
  private readonly workspace = inject(WorkspaceService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly task = input.required<CareTaskFull>();

  readonly assigned = output<CareTask>();
  readonly cancelled = output<void>();
  readonly reloadRequested = output<void>();

  protected readonly members = this.workspace.members;
  protected readonly membersLoading = this.workspace.membersLoading;
  protected readonly membersError = this.workspace.membersError;

  protected readonly roleOptions = ROLE_OPTIONS;

  protected readonly form = this.formBuilder.group({
    user_id: [''],
    role: ['primary'],
    can_complete: [false],
  });

  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly noMembers = computed(
    () => !this.membersLoading() && this.members().length === 0,
  );

  constructor() {
    void this.workspace.loadMembers();
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected memberName(name: string | null, userId: number): string {
    return name === null || name.trim() === '' ? `ผู้ใช้ #${userId}` : name;
  }

  protected memberRole(role: string): string {
    return this.roleOptions.find((option) => option.value === role)?.label ?? role;
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);
    this.fieldErrors.set({});

    const raw = this.form.getRawValue();
    const userId = Number(raw.user_id);

    if (raw.user_id.trim() === '' || !Number.isInteger(userId) || userId <= 0) {
      this.fieldErrors.set({ user_id: 'กรุณาเลือกผู้รับผิดชอบ' });
      return;
    }

    const role = ROLE_OPTIONS.some((option) => option.value === raw.role) ? raw.role : 'primary';

    this.submitting.set(true);

    try {
      const task = this.task();
      const body: Record<string, unknown> = {
        user_id: userId,
        role,
        can_complete: raw.can_complete,
      };
      const updated = await this.careTasks.act(task.id, task.version, 'assign', body);

      this.assigned.emit(updated);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        return;
      }

      const serverErrors = readFieldErrors(error);

      if (Object.keys(serverErrors).length > 0) {
        this.fieldErrors.set(serverErrors);
      } else {
        this.formError.set(toUserMessage(error));
      }
    } finally {
      this.submitting.set(false);
    }
  }
}
