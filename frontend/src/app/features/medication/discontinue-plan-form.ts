import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import {
  DISCONTINUE_REASON_MAX_LENGTH,
  DISCONTINUE_REASON_MIN_LENGTH,
  MedicationPlan,
  medicationPlanStatusLabel,
} from '../../core/medication/medication.models';
import { MedicationService } from '../../core/medication/medication.service';

/**
 * Discontinuing is a separate transition with a mandatory reason; it is never
 * an edit of the plan. The request sends the plan's current `If-Match` version.
 */
@Component({
  selector: 'app-discontinue-plan-form',
  imports: [ReactiveFormsModule],
  templateUrl: './discontinue-plan-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DiscontinuePlanForm {
  private readonly medication = inject(MedicationService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly plan = input.required<MedicationPlan>();

  readonly discontinued = output<MedicationPlan>();
  readonly cancelled = output<void>();
  readonly reloadRequested = output<void>();

  protected readonly form = this.formBuilder.group({
    reason: [''],
  });

  protected readonly reasonError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly minimumLength = DISCONTINUE_REASON_MIN_LENGTH;

  protected statusLabel(plan: MedicationPlan): string {
    return medicationPlanStatusLabel(plan.status);
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);
    this.reasonError.set(null);

    const reason = this.form.getRawValue().reason.trim();

    if (reason.length < DISCONTINUE_REASON_MIN_LENGTH) {
      this.reasonError.set(`กรุณาระบุเหตุผลอย่างน้อย ${DISCONTINUE_REASON_MIN_LENGTH} ตัวอักษร`);
      return;
    }

    if (reason.length > DISCONTINUE_REASON_MAX_LENGTH) {
      this.reasonError.set('เหตุผลต้องไม่เกิน 1,000 ตัวอักษร');
      return;
    }

    this.submitting.set(true);

    try {
      const plan = this.plan();
      const updated = await this.medication.discontinuePlan(
        this.profileId(),
        plan.id,
        plan.version,
        reason,
      );

      this.discontinued.emit(updated);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        return;
      }

      const serverErrors = readFieldErrors(error);

      if (serverErrors['reason'] !== undefined) {
        this.reasonError.set(serverErrors['reason']);
      } else {
        this.formError.set(toUserMessage(error));
      }
    } finally {
      this.submitting.set(false);
    }
  }
}
