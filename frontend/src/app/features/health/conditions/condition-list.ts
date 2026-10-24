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
import { Condition, conditionStatusLabel } from '../../../core/health-record/health-record.models';
import { formatThaiDate } from '../../../core/health-record/health-record.format';
import { HealthRecordService } from '../../../core/health-record/health-record.service';
import { ConditionForm } from './condition-form';

@Component({
  selector: 'app-condition-list',
  imports: [ConditionForm],
  templateUrl: './condition-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConditionList {
  private readonly health = inject(HealthRecordService);

  readonly profileId = input.required<string>();
  readonly saved = output<Condition>();

  protected readonly conditions = this.health.conditions;
  protected readonly loading = this.health.conditionsLoading;
  protected readonly error = this.health.conditionsError;

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<Condition | null>(null);
  protected readonly skeletons = [1, 2, 3];

  constructor() {
    effect(() => {
      const profileId = this.profileId();

      untracked(() => {
        this.reload(profileId);
      });
    });
  }

  protected reload(profileId: string = this.profileId()): void {
    void this.health.listConditions(profileId, { limit: 50 });
  }

  protected openCreate(): void {
    this.editing.set(null);
    this.formOpen.set(true);
  }

  protected openEdit(condition: Condition): void {
    this.editing.set(condition);
    this.formOpen.set(true);
  }

  protected closeForm(): void {
    this.formOpen.set(false);
    this.editing.set(null);
  }

  protected onSaved(condition: Condition): void {
    this.formOpen.set(false);
    this.editing.set(null);
    this.saved.emit(condition);
  }

  protected onReloadRequested(): void {
    this.formOpen.set(false);
    this.editing.set(null);
    this.reload();
  }

  protected statusLabel(condition: Condition): string {
    return conditionStatusLabel(condition.status);
  }

  protected diagnosedLabel(condition: Condition): string {
    return formatThaiDate(condition.diagnosed_at);
  }
}
