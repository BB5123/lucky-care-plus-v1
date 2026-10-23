import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../../core/api/problem-details';
import {
  CONDITION_STATUSES,
  Condition,
  ConditionDraft,
  buildConditionBody,
  conditionStatusLabel,
  isConditionStatus,
  validateConditionDraft,
} from '../../../core/health-record/health-record.models';
import { HealthRecordService } from '../../../core/health-record/health-record.service';

@Component({
  selector: 'app-condition-form',
  imports: [ReactiveFormsModule],
  templateUrl: './condition-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConditionForm {
  private readonly health = inject(HealthRecordService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly editing = input<Condition | null>(null);

  readonly saved = output<Condition>();
  readonly cancelled = output<void>();
  readonly reloadRequested = output<void>();

  protected readonly form = this.formBuilder.group({
    code: [''],
    name: [''],
    diagnosed_at: [''],
    status: this.formBuilder.control('active'),
    notes: [''],
  });

  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly statusOptions = CONDITION_STATUSES.map((status) => ({
    value: status,
    label: conditionStatusLabel(status),
  }));

  constructor() {
    effect(() => {
      const condition = this.editing();

      untracked(() => {
        this.fill(condition);
      });
    });
  }

  protected isEditing(): boolean {
    return this.editing() !== null;
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);

    const raw = this.form.getRawValue();
    const draft: ConditionDraft = {
      code: raw.code,
      name: raw.name,
      diagnosed_at: raw.diagnosed_at,
      status: raw.status,
      notes: raw.notes,
    };
    const clientErrors = validateConditionDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      this.form.markAllAsTouched();
      return;
    }

    this.fieldErrors.set({});
    this.submitting.set(true);

    try {
      const body = buildConditionBody(draft);
      const editing = this.editing();
      const saved =
        editing === null
          ? await this.health.createCondition(this.profileId(), body)
          : await this.health.updateCondition(this.profileId(), editing.id, editing.version, body);

      this.saved.emit(saved);
    } catch (error) {
      // AP-007: a stale If-Match is a conflict, not a generic failure. Keep the
      // dedicated Thai sentence plus a reload action so the user can re-read.
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        this.fieldErrors.set({});
        this.formError.set(null);
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

  private fill(condition: Condition | null): void {
    this.form.reset({
      code: condition?.code ?? '',
      name: condition?.name ?? '',
      diagnosed_at: condition?.diagnosed_at ?? '',
      status:
        condition !== null && isConditionStatus(condition.status) ? condition.status : 'active',
      notes: condition?.notes ?? '',
    });
    this.fieldErrors.set({});
    this.formError.set(null);
    this.conflict.set(false);
  }
}
