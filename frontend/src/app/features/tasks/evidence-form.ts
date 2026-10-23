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
import {
  EVIDENCE_METRICS,
  EvidenceDraft,
  EvidenceType,
  TaskEvidence,
  buildEvidenceBody,
  isEvidenceType,
  validateEvidenceDraft,
} from '../../core/caretask/caretask.models';
import { CareTaskService } from '../../core/caretask/caretask.service';

/**
 * Adds `value` or `note` evidence to a clinical task.
 *
 * Photo evidence needs a document id that this slice cannot obtain, so the
 * control is not offered at all. A photo/both policy therefore stays
 * unsatisfied and completion stays disabled (fail closed) until the document
 * flow exists.
 */
@Component({
  selector: 'app-evidence-form',
  imports: [ReactiveFormsModule],
  templateUrl: './evidence-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EvidenceForm {
  private readonly careTasks = inject(CareTaskService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly taskId = input.required<string>();
  readonly version = input(0);

  readonly saved = output<TaskEvidence>();
  readonly reloadRequested = output<void>();

  protected readonly form = this.formBuilder.group({
    type: this.formBuilder.control('value'),
    metric: [EVIDENCE_METRICS[0]?.value ?? ''],
    value: [''],
    note: [''],
  });

  protected readonly selectedType = signal<EvidenceType>('value');
  protected readonly selectedMetric = signal(EVIDENCE_METRICS[0]?.value ?? '');
  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly metricOptions = EVIDENCE_METRICS;
  protected readonly typeOptions: ReadonlyArray<{ value: EvidenceType; label: string }> = [
    { value: 'value', label: 'ค่าที่วัด' },
    { value: 'note', label: 'บันทึกข้อความ' },
  ];

  protected readonly selectedUnit = computed(
    () => EVIDENCE_METRICS.find((option) => option.value === this.selectedMetric())?.unit ?? '',
  );

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected changeType(event: Event): void {
    const target = event.target;

    if (target instanceof HTMLSelectElement && isEvidenceType(target.value)) {
      this.selectedType.set(target.value);
      this.fieldErrors.set({});
      this.formError.set(null);
    }
  }

  protected changeMetric(event: Event): void {
    const target = event.target;

    if (target instanceof HTMLSelectElement) {
      this.selectedMetric.set(target.value);
    }
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.fieldErrors.set({});
    this.formError.set(null);
    this.conflict.set(false);

    const raw = this.form.getRawValue();
    const type = isEvidenceType(raw.type) ? raw.type : 'note';
    const metric = EVIDENCE_METRICS.find((option) => option.value === raw.metric);
    const draft: EvidenceDraft = {
      type,
      metric: type === 'value' ? raw.metric : '',
      value: type === 'value' ? raw.value : null,
      unit: type === 'value' ? (metric?.unit ?? '') : '',
      note: type === 'note' ? raw.note : '',
    };
    const clientErrors = validateEvidenceDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      return;
    }

    this.submitting.set(true);

    try {
      const created = await this.careTasks.addEvidence(
        this.taskId(),
        buildEvidenceBody(draft),
        this.version(),
      );

      this.form.controls.value.setValue('');
      this.form.controls.note.setValue('');
      this.saved.emit(created);
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
