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
import { Allergy, allergySeverityLabel } from '../../../core/health-record/health-record.models';
import { formatThaiDate } from '../../../core/health-record/health-record.format';
import { HealthRecordService } from '../../../core/health-record/health-record.service';
import { AllergyForm } from './allergy-form';

@Component({
  selector: 'app-allergy-list',
  imports: [AllergyForm],
  templateUrl: './allergy-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AllergyList {
  private readonly health = inject(HealthRecordService);

  readonly profileId = input.required<string>();
  readonly saved = output<Allergy>();

  protected readonly allergies = this.health.allergies;
  protected readonly loading = this.health.allergiesLoading;
  protected readonly error = this.health.allergiesError;

  protected readonly formOpen = signal(false);
  protected readonly editing = signal<Allergy | null>(null);
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
    void this.health.listAllergies(profileId, { limit: 50 });
  }

  protected openCreate(): void {
    this.editing.set(null);
    this.formOpen.set(true);
  }

  protected openEdit(allergy: Allergy): void {
    this.editing.set(allergy);
    this.formOpen.set(true);
  }

  protected closeForm(): void {
    this.formOpen.set(false);
    this.editing.set(null);
  }

  protected onSaved(allergy: Allergy): void {
    this.formOpen.set(false);
    this.editing.set(null);
    this.saved.emit(allergy);
  }

  protected onReloadRequested(): void {
    this.formOpen.set(false);
    this.editing.set(null);
    this.reload();
  }

  protected severityLabel(allergy: Allergy): string {
    return allergySeverityLabel(allergy.severity);
  }

  protected notedLabel(allergy: Allergy): string {
    return formatThaiDate(allergy.noted_at);
  }
}
