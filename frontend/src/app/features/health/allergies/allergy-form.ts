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
  ALLERGY_SEVERITIES,
  Allergy,
  AllergyDraft,
  allergySeverityLabel,
  buildAllergyBody,
  isAllergySeverity,
  validateAllergyDraft,
} from '../../../core/health-record/health-record.models';
import { HealthRecordService } from '../../../core/health-record/health-record.service';

@Component({
  selector: 'app-allergy-form',
  imports: [ReactiveFormsModule],
  templateUrl: './allergy-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AllergyForm {
  private readonly health = inject(HealthRecordService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly editing = input<Allergy | null>(null);

  readonly saved = output<Allergy>();
  readonly cancelled = output<void>();
  readonly reloadRequested = output<void>();

  protected readonly form = this.formBuilder.group({
    substance: [''],
    reaction: [''],
    severity: this.formBuilder.control('moderate'),
    noted_at: [''],
  });

  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly severityOptions = ALLERGY_SEVERITIES.map((severity) => ({
    value: severity,
    label: allergySeverityLabel(severity),
  }));

  constructor() {
    effect(() => {
      const allergy = this.editing();

      untracked(() => {
        this.fill(allergy);
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
    const draft: AllergyDraft = {
      substance: raw.substance,
      reaction: raw.reaction,
      severity: raw.severity,
      noted_at: raw.noted_at,
    };
    const clientErrors = validateAllergyDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      this.form.markAllAsTouched();
      return;
    }

    this.fieldErrors.set({});
    this.submitting.set(true);

    try {
      const saved = await this.health.upsertAllergy(
        this.profileId(),
        buildAllergyBody(draft),
        this.editing(),
      );

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

  private fill(allergy: Allergy | null): void {
    this.form.reset({
      substance: allergy?.substance ?? '',
      reaction: allergy?.reaction ?? '',
      severity:
        allergy !== null && isAllergySeverity(allergy.severity) ? allergy.severity : 'moderate',
      noted_at: allergy?.noted_at ?? '',
    });
    this.fieldErrors.set({});
    this.formError.set(null);
    this.conflict.set(false);
  }
}
