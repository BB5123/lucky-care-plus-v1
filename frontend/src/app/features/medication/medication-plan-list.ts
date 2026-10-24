import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { formatThaiDate } from '../../core/health-record/health-record.format';
import {
  MedicationPlan,
  medicationFormLabel,
  medicationPlanStatusLabel,
  medicationRouteLabel,
  sortMedicationPlans,
} from '../../core/medication/medication.models';
import { MedicationService } from '../../core/medication/medication.service';
import { AdherenceCard } from './adherence-card';
import { DiscontinuePlanForm } from './discontinue-plan-form';
import { MedicationPlanForm } from './medication-plan-form';
import { MedicationRecordForm } from './medication-record-form';

@Component({
  selector: 'app-medication-plan-list',
  imports: [AdherenceCard, DiscontinuePlanForm, MedicationPlanForm, MedicationRecordForm],
  templateUrl: './medication-plan-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MedicationPlanList {
  private readonly medication = inject(MedicationService);

  readonly profileId = input.required<string>();
  readonly saved = output<void>();

  protected readonly plans = this.medication.plans;
  protected readonly loading = this.medication.plansLoading;
  protected readonly error = this.medication.plansError;
  protected readonly adherence = this.medication.adherence;
  protected readonly adherenceMeta = this.medication.adherenceMeta;
  protected readonly adherenceLoading = this.medication.adherenceLoading;
  protected readonly adherenceError = this.medication.adherenceError;

  protected readonly planFormOpen = signal(false);
  protected readonly editing = signal<MedicationPlan | null>(null);
  protected readonly discontinuing = signal<MedicationPlan | null>(null);
  protected readonly recordOpen = signal(false);
  protected readonly recordPlanId = signal<string | null>(null);
  protected readonly skeletons = [1, 2, 3];

  protected readonly sortedPlans = computed(() => sortMedicationPlans(this.plans()));
  protected readonly activePlans = computed(() =>
    this.plans().filter((plan) => plan.status === 'active'),
  );

  constructor() {
    effect(() => {
      const profileId = this.profileId();

      untracked(() => {
        this.reload(profileId);
      });
    });
  }

  protected reload(profileId: string = this.profileId()): void {
    void this.medication.listPlans(profileId, { limit: 50 });
    void this.medication.loadAdherence(profileId, 7);
  }

  protected openCreate(): void {
    this.editing.set(null);
    this.discontinuing.set(null);
    this.planFormOpen.set(true);
  }

  protected openEdit(plan: MedicationPlan): void {
    this.editing.set(plan);
    this.discontinuing.set(null);
    this.planFormOpen.set(true);
  }

  protected closeForm(): void {
    this.planFormOpen.set(false);
    this.editing.set(null);
  }

  protected onPlanSaved(): void {
    this.closeForm();
    this.saved.emit();
  }

  protected openDiscontinue(plan: MedicationPlan): void {
    this.planFormOpen.set(false);
    this.editing.set(null);
    this.discontinuing.set(plan);
  }

  protected closeDiscontinue(): void {
    this.discontinuing.set(null);
  }

  protected onDiscontinued(): void {
    this.discontinuing.set(null);
    this.saved.emit();
  }

  protected openRecord(planId: string | null = null): void {
    this.recordPlanId.set(planId);
    this.recordOpen.set(true);
  }

  protected closeRecord(): void {
    this.recordOpen.set(false);
    this.recordPlanId.set(null);
  }

  protected onRecordSaved(): void {
    this.closeRecord();
    this.saved.emit();
  }

  protected onReloadRequested(): void {
    this.closeForm();
    this.closeDiscontinue();
    this.reload();
  }

  protected isActive(plan: MedicationPlan): boolean {
    return plan.status === 'active';
  }

  protected statusLabel(plan: MedicationPlan): string {
    return medicationPlanStatusLabel(plan.status);
  }

  protected formLabel(plan: MedicationPlan): string {
    return medicationFormLabel(plan.form);
  }

  protected routeLabel(plan: MedicationPlan): string {
    return medicationRouteLabel(plan.route);
  }

  protected windowLabel(plan: MedicationPlan): string {
    const start = formatThaiDate(plan.starts_on);
    const end = plan.ends_on === null ? 'ต่อเนื่อง' : formatThaiDate(plan.ends_on);

    return `${start} – ${end}`;
  }

  protected scheduleLabel(plan: MedicationPlan): string {
    const times =
      plan.schedule_times.length > 0 ? plan.schedule_times.join(' · ') : 'ยังไม่ตั้งเวลา';

    return `${plan.doses_per_day} มื้อ/วัน · ${times}`;
  }
}
