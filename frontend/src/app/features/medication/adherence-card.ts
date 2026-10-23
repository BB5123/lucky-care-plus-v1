import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { formatHealthNumber, formatThaiDate } from '../../core/health-record/health-record.format';
import {
  MedicationAdherence,
  MedicationAdherenceMeta,
} from '../../core/medication/medication.models';

/**
 * Seven-day adherence exactly as the API computed it.
 *
 * `expected`, `taken`, `skipped`, `missed` and `percent` are read from the
 * payload; this component never divides them again. The daily bars use the
 * server's per-day `percent`, clamped only so a malformed 150% cannot paint
 * outside its track.
 */
@Component({
  selector: 'app-adherence-card',
  templateUrl: './adherence-card.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdherenceCard {
  readonly rows = input<readonly MedicationAdherence[]>([]);
  readonly meta = input<MedicationAdherenceMeta | null>(null);
  readonly loading = input(false);

  protected percentLabel(percent: number): string {
    return `${formatHealthNumber(percent)}%`;
  }

  protected countLabel(value: number): string {
    return formatHealthNumber(value);
  }

  protected dayLabel(date: string): string {
    return formatThaiDate(date);
  }

  protected barWidth(percent: number): number {
    if (!Number.isFinite(percent)) {
      return 0;
    }

    return Math.max(0, Math.min(100, percent));
  }
}
