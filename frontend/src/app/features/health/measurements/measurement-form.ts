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
import { httpStatus, readFieldErrors, toUserMessage } from '../../../core/api/problem-details';
import {
  BLOOD_GLUCOSE_CONTEXTS,
  MEASUREMENT_TYPES,
  MEASUREMENT_TYPE_INFO,
  Measurement,
  MeasurementDraft,
  MeasurementType,
  buildMeasurementBody,
  isMeasurementType,
  measurementContextLabel,
  validateMeasurementDraft,
} from '../../../core/health-record/health-record.models';
import { HealthRecordService } from '../../../core/health-record/health-record.service';

@Component({
  selector: 'app-measurement-form',
  imports: [ReactiveFormsModule],
  templateUrl: './measurement-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MeasurementForm {
  private readonly health = inject(HealthRecordService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly saved = output<Measurement>();
  readonly cancelled = output<void>();

  protected readonly form = this.formBuilder.group({
    type: this.formBuilder.control<MeasurementType>('blood_pressure'),
    value: [''],
    secondary_value: [''],
    context: [''],
    note: [''],
  });

  protected readonly selectedType = signal<MeasurementType>('blood_pressure');
  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly submitting = signal(false);

  protected readonly typeInfo = computed(() => MEASUREMENT_TYPE_INFO[this.selectedType()]);

  protected readonly typeOptions = MEASUREMENT_TYPES.map((type) => ({
    value: type,
    label: MEASUREMENT_TYPE_INFO[type].label,
  }));

  protected readonly contextOptions = BLOOD_GLUCOSE_CONTEXTS.map((context) => ({
    value: context,
    label: measurementContextLabel(context),
  }));

  protected primaryLabel(): string {
    return this.selectedType() === 'blood_pressure' ? 'ค่าบน (systolic)' : 'ค่าที่วัด';
  }

  protected primaryHint(): string {
    const info = this.typeInfo();

    return `หน่วย: ${info.unit} · ช่วงที่รับได้: ${info.minimum}–${info.maximum} ${info.unit}`;
  }

  protected secondaryHint(): string {
    const info = this.typeInfo();

    if (info.secondaryMinimum === null || info.secondaryMaximum === null) {
      return '';
    }

    return `หน่วย: ${info.unit} · ช่วงที่รับได้: ${info.secondaryMinimum}–${info.secondaryMaximum} ${info.unit} และต้องน้อยกว่าค่าบน`;
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected onTypeChange(event: Event): void {
    const target = event.target as HTMLSelectElement | null;

    if (target === null || !isMeasurementType(target.value)) {
      return;
    }

    const type = target.value;

    this.selectedType.set(type);
    this.fieldErrors.set({});
    this.formError.set(null);

    if (type !== 'blood_pressure') {
      this.form.controls.secondary_value.setValue('');
    }

    if (type !== 'blood_glucose') {
      this.form.controls.context.setValue('');
    }
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);

    const raw = this.form.getRawValue();
    const draft: MeasurementDraft = {
      type: this.selectedType(),
      value: raw.value,
      secondary_value: raw.secondary_value,
      context: raw.context,
      note: raw.note,
    };
    const clientErrors = validateMeasurementDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      this.form.markAllAsTouched();
      return;
    }

    this.fieldErrors.set({});
    this.submitting.set(true);

    try {
      const created = await this.health.createMeasurement(
        this.profileId(),
        buildMeasurementBody(draft),
      );

      this.resetForm();
      this.saved.emit(created);
    } catch (error) {
      // The server is authoritative: bind its `errors{}` to the matching
      // control, and keep a 422 without field details inline in the form.
      const serverErrors = readFieldErrors(error);

      if (Object.keys(serverErrors).length > 0) {
        this.fieldErrors.set(serverErrors);
      } else {
        const status = httpStatus(error);
        this.formError.set(
          status === 422 ? 'ข้อมูลไม่ผ่านการตรวจสอบ กรุณาตรวจสอบค่าที่กรอก' : toUserMessage(error),
        );
      }
    } finally {
      this.submitting.set(false);
    }
  }

  private resetForm(): void {
    this.form.reset({
      type: 'blood_pressure',
      value: '',
      secondary_value: '',
      context: '',
      note: '',
    });
    this.selectedType.set('blood_pressure');
    this.fieldErrors.set({});
    this.formError.set(null);
  }
}
