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
import {
  Measurement,
  measurementContextLabel,
} from '../../../core/health-record/health-record.models';
import {
  formatHealthNumber,
  formatMeasurementValue,
  formatThaiDateTime,
  measurementDisplayUnit,
} from '../../../core/health-record/health-record.format';
import { HealthRecordService } from '../../../core/health-record/health-record.service';
import {
  MeasurementGroup,
  MeasurementStats,
  groupMeasurements,
} from '../../../core/health-record/health-record.stats';
import { MeasurementForm } from './measurement-form';

@Component({
  selector: 'app-measurement-list',
  imports: [MeasurementForm],
  templateUrl: './measurement-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MeasurementList {
  private readonly health = inject(HealthRecordService);

  readonly profileId = input.required<string>();
  readonly saved = output<Measurement>();

  protected readonly measurements = this.health.measurements;
  protected readonly loading = this.health.measurementsLoading;
  protected readonly error = this.health.measurementsError;
  protected readonly nextCursor = this.health.measurementsNextCursor;

  protected readonly formOpen = signal(false);
  protected readonly skeletons = [1, 2, 3];

  protected readonly groups = computed(() => groupMeasurements(this.measurements()));

  constructor() {
    effect(() => {
      const profileId = this.profileId();

      untracked(() => {
        void this.reload(profileId);
      });
    });
  }

  protected reload(profileId: string = this.profileId()): void {
    void this.health.listMeasurements(profileId, { limit: 50 });
  }

  protected loadMore(): void {
    const cursor = this.nextCursor();

    if (cursor === null) {
      return;
    }

    void this.health.listMeasurements(this.profileId(), { limit: 50, cursor }, true);
  }

  protected openForm(): void {
    this.formOpen.set(true);
  }

  protected closeForm(): void {
    this.formOpen.set(false);
  }

  protected onSaved(measurement: Measurement): void {
    this.formOpen.set(false);
    this.saved.emit(measurement);
  }

  protected valueLabel(measurement: Measurement): string {
    return formatMeasurementValue(measurement);
  }

  protected unitLabel(measurement: Measurement): string {
    return measurementDisplayUnit(measurement);
  }

  protected recordedLabel(measurement: Measurement): string {
    return formatThaiDateTime(measurement.recorded_at);
  }

  protected contextLabel(measurement: Measurement): string {
    return measurementContextLabel(measurement.context);
  }

  protected statsLabel(group: MeasurementGroup): string {
    const stats = group.stats7d;

    if (stats === null) {
      return 'ยังไม่มีข้อมูลใน 7 วันล่าสุด';
    }

    return this.statsText(stats, group.unit);
  }

  private statsText(stats: MeasurementStats, unit: string): string {
    return `7 วัน: ต่ําสุด ${formatHealthNumber(stats.min)} · สูงสุด ${formatHealthNumber(
      stats.max,
    )} · เฉลี่ย ${formatHealthNumber(stats.avg)} ${unit} (${stats.count} ครั้ง)`;
  }
}
