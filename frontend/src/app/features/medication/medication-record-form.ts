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
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import {
  MEDICATION_RECORD_STATUSES,
  MedicationPlan,
  MedicationRecord,
  MedicationRecordDraft,
  buildMedicationRecordBody,
  medicationRecordStatusLabel,
  validateMedicationRecordDraft,
} from '../../core/medication/medication.models';
import { MedicationService } from '../../core/medication/medication.service';

/**
 * Confirm one reported dose against an active plan.
 *
 * A `taken` row is self-reported, never clinical proof of ingestion; the note
 * below the control repeats that sentence. A `skipped` row must explain why.
 */
@Component({
  selector: 'app-medication-record-form',
  imports: [ReactiveFormsModule],
  templateUrl: './medication-record-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MedicationRecordForm {
  private readonly medication = inject(MedicationService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly plans = input<readonly MedicationPlan[]>([]);
  readonly defaultPlanId = input<string | null>(null);

  readonly saved = output<MedicationRecord>();
  readonly cancelled = output<void>();

  protected readonly form = this.formBuilder.group({
    plan_id: [''],
    status: this.formBuilder.control('taken'),
    note: [''],
    scheduled_for: [''],
  });

  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly submitting = signal(false);
  protected readonly selfReportedNotice =
    'การยืนยันนี้เป็นการรายงานด้วยตนเอง ไม่ใช่หลักฐานทางการแพทย์';

  protected readonly statusOptions = MEDICATION_RECORD_STATUSES.map((status) => ({
    value: status,
    label: medicationRecordStatusLabel(status),
  }));

  constructor() {
    effect(() => {
      const plans = this.plans();
      const defaultPlanId = this.defaultPlanId();

      untracked(() => {
        const current = this.form.controls.plan_id.value;
        const chosen =
          defaultPlanId !== null && plans.some((plan) => plan.id === defaultPlanId)
            ? defaultPlanId
            : plans.some((plan) => plan.id === current)
              ? current
              : (plans[0]?.id ?? '');

        this.form.controls.plan_id.setValue(chosen);
      });
    });
  }

  protected isSkipped(): boolean {
    return this.form.controls.status.value === 'skipped';
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);

    const raw = this.form.getRawValue();
    const draft: MedicationRecordDraft = {
      planId: raw.plan_id,
      status: raw.status,
      note: raw.note,
      scheduledFor: raw.scheduled_for,
    };
    const clientErrors = validateMedicationRecordDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      return;
    }

    this.fieldErrors.set({});
    this.submitting.set(true);

    try {
      const saved = await this.medication.createRecord(
        this.profileId(),
        buildMedicationRecordBody(draft),
      );

      this.saved.emit(saved);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.formError.set('ข้อมูลถูกแก้ไขโดยคนอื่น กรุณาโหลดข้อมูลใหม่');
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
