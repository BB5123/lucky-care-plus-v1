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
import {
  FormArray,
  FormControl,
  NonNullableFormBuilder,
  ReactiveFormsModule,
} from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import {
  DOSES_PER_DAY_MAX,
  DOSES_PER_DAY_MIN,
  MEDICATION_FORMS,
  MEDICATION_ROUTES,
  MedicationPlan,
  MedicationPlanDraft,
  buildMedicationPlanBody,
  isMedicationForm,
  isMedicationRoute,
  medicationFormLabel,
  medicationRouteLabel,
  validateMedicationPlanDraft,
} from '../../core/medication/medication.models';
import { MedicationService } from '../../core/medication/medication.service';

const DEFAULT_TIMES = [
  '08:00',
  '12:00',
  '18:00',
  '20:00',
  '09:00',
  '15:00',
  '21:00',
  '07:00',
  '13:00',
  '19:00',
  '22:00',
  '06:00',
];

/**
 * Create/edit a medication plan.
 *
 * `doses_per_day` is not an editable field: it is the number of time rows. A
 * single add/remove action changes both together, so the two can never
 * disagree (the API rejects a mismatch with 422 as a backstop).
 */
@Component({
  selector: 'app-medication-plan-form',
  imports: [ReactiveFormsModule],
  templateUrl: './medication-plan-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MedicationPlanForm {
  private readonly medication = inject(MedicationService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly editing = input<MedicationPlan | null>(null);

  readonly saved = output<MedicationPlan>();
  readonly cancelled = output<void>();
  readonly reloadRequested = output<void>();

  protected readonly form = this.formBuilder.group({
    name: [''],
    dosage: [''],
    form: this.formBuilder.control('tablet'),
    route: this.formBuilder.control('oral'),
    schedule_times: this.formBuilder.array<FormControl<string>>([
      this.formBuilder.control('08:00'),
    ]),
    starts_on: [''],
    ends_on: [''],
    prescribed_by: [''],
    instructions: [''],
  });

  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly dosesMinimum = DOSES_PER_DAY_MIN;
  protected readonly dosesMaximum = DOSES_PER_DAY_MAX;

  protected readonly formOptions = MEDICATION_FORMS.map((form) => ({
    value: form,
    label: medicationFormLabel(form),
  }));

  protected readonly routeOptions = MEDICATION_ROUTES.map((route) => ({
    value: route,
    label: medicationRouteLabel(route),
  }));

  constructor() {
    effect(() => {
      const plan = this.editing();

      untracked(() => {
        this.fill(plan);
      });
    });
  }

  protected isEditing(): boolean {
    return this.editing() !== null;
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected timeControls(): readonly FormControl<string>[] {
    return this.form.controls.schedule_times.controls;
  }

  /** The single source of truth for both the label and the request body. */
  protected dosesPerDay(): number {
    return this.form.controls.schedule_times.length;
  }

  protected canAddTime(): boolean {
    return this.dosesPerDay() < DOSES_PER_DAY_MAX;
  }

  protected canRemoveTime(): boolean {
    return this.dosesPerDay() > DOSES_PER_DAY_MIN;
  }

  protected addTime(): void {
    if (!this.canAddTime()) {
      return;
    }

    const used = new Set(this.timeControls().map((control) => control.value));
    const next = DEFAULT_TIMES.find((time) => !used.has(time)) ?? '08:00';

    this.form.controls.schedule_times.push(this.formBuilder.control(next));
  }

  protected removeTime(index: number): void {
    if (!this.canRemoveTime()) {
      return;
    }

    this.form.controls.schedule_times.removeAt(index);
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);

    const raw = this.form.getRawValue();
    const times = raw.schedule_times.map((time) => time.trim());
    const draft: MedicationPlanDraft = {
      name: raw.name,
      dosage: raw.dosage,
      form: raw.form,
      route: raw.route,
      dosesPerDay: times.length,
      scheduleTimes: times,
      startsOn: raw.starts_on,
      endsOn: raw.ends_on,
      prescribedBy: raw.prescribed_by,
      instructions: raw.instructions,
    };
    const clientErrors = validateMedicationPlanDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      this.form.markAllAsTouched();
      return;
    }

    this.fieldErrors.set({});
    this.submitting.set(true);

    try {
      const body = buildMedicationPlanBody(draft);
      const editing = this.editing();
      const saved =
        editing === null
          ? await this.medication.createPlan(this.profileId(), body)
          : await this.medication.updatePlan(this.profileId(), editing.id, editing.version, body);

      this.saved.emit(saved);
    } catch (error) {
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

  private fill(plan: MedicationPlan | null): void {
    const times = plan !== null && plan.schedule_times.length > 0 ? plan.schedule_times : ['08:00'];

    this.form.controls.schedule_times.clear();

    for (const time of times.slice(0, DOSES_PER_DAY_MAX)) {
      this.form.controls.schedule_times.push(this.formBuilder.control(time));
    }

    this.form.patchValue({
      name: plan?.name ?? '',
      dosage: plan?.dosage ?? '',
      form: plan !== null && isMedicationForm(plan.form) ? plan.form : 'tablet',
      route: plan !== null && isMedicationRoute(plan.route ?? '') ? (plan.route ?? 'oral') : 'oral',
      starts_on: plan?.starts_on ?? '',
      ends_on: plan?.ends_on ?? '',
      prescribed_by: plan?.prescribed_by ?? '',
      instructions: plan?.instructions ?? '',
    });
    this.fieldErrors.set({});
    this.formError.set(null);
    this.conflict.set(false);
  }
}
