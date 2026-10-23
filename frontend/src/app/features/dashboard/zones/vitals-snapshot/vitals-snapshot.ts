import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { VitalCard, VitalsZone } from '../../../../core/dashboard/dashboard.models';

interface SparkPoint {
  readonly min: number;
  readonly max: number;
  readonly avg: number;
}

/**
 * Z4 — Vitals snapshot in a 2×2 grid.
 *
 * The backend's `trend_7d` is a summary (`count/min/max/avg`), not a series.
 * The sparkline therefore visualises the seven-day range as a band plus the
 * average marker; no data is invented to fake a time series. No chart library
 * is used.
 */
@Component({
  selector: 'app-vitals-snapshot',
  templateUrl: './vitals-snapshot.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VitalsSnapshot {
  readonly zone = input<VitalsZone | null>(null);
  readonly loading = input(false);
  readonly error = input<string | null>(null);
  readonly showSparkline = input(true);

  readonly retry = output<void>();

  protected readonly skeletons = [1, 2, 3, 4];

  private readonly numberFormat = new Intl.NumberFormat('th-TH', {
    maximumFractionDigits: 1,
  });

  protected typeLabel(type: string): string {
    switch (type) {
      case 'blood_pressure':
        return 'ความดันโลหิต';
      case 'blood_glucose':
        return 'น้ำตาลในเลือด';
      case 'heart_rate':
        return 'ชีพจร';
      case 'weight':
        return 'น้ำหนัก';
      case 'spo2':
        return 'ออกซิเจนในเลือด';
      case 'temperature':
        return 'อุณหภูมิ';
      default:
        return 'ค่าสุขภาพ';
    }
  }

  protected displayValue(card: VitalCard): string {
    const primary = this.formatNumber(card.latest.value);

    if (card.type === 'blood_pressure' && card.latest.secondary_value !== null) {
      return `${primary}/${this.formatNumber(card.latest.secondary_value)}`;
    }

    return primary;
  }

  protected unit(card: VitalCard): string {
    if (card.latest.unit !== '') {
      return card.latest.unit;
    }

    switch (card.type) {
      case 'blood_pressure':
        return 'mmHg';
      case 'blood_glucose':
        return 'mg/dL';
      case 'heart_rate':
        return 'bpm';
      case 'weight':
        return 'kg';
      case 'spo2':
        return '%';
      case 'temperature':
        return '°C';
      default:
        return '';
    }
  }

  protected minValue(card: VitalCard): string {
    return this.formatNumber(card.trend_7d.min);
  }

  protected maxValue(card: VitalCard): string {
    return this.formatNumber(card.trend_7d.max);
  }

  protected avgValue(card: VitalCard): string {
    return this.formatNumber(card.trend_7d.avg);
  }

  protected statusLabel(status: string): string {
    switch (status) {
      case 'critical':
        return 'ต้องดูแลทันที';
      case 'high':
      case 'warning':
      case 'yellow':
        return 'ผิดปกติเล็กน้อย';
      case 'normal':
      case 'green':
        return 'ปกติ';
      default:
        return 'ไม่ทราบสถานะ';
    }
  }

  protected hasSparkline(card: VitalCard): boolean {
    return this.showSparkline() && this.sparkPoint(card) !== null;
  }

  protected sparkAvgY(card: VitalCard): number {
    const point = this.sparkPoint(card);

    if (point === null || point.max <= point.min) {
      return 14;
    }

    const ratio = (point.avg - point.min) / (point.max - point.min);

    return 24 - Math.min(1, Math.max(0, ratio)) * 20;
  }

  protected sparklineLabel(card: VitalCard): string {
    return `${this.typeLabel(card.type)} แนวโน้ม 7 วัน ต่ำสุด ${this.minValue(
      card,
    )} สูงสุด ${this.maxValue(card)} เฉลี่ย ${this.avgValue(card)} จำนวน ${
      card.trend_7d.count
    } ครั้ง`;
  }

  private sparkPoint(card: VitalCard): SparkPoint | null {
    const { min, max, avg, count } = card.trend_7d;

    if (count < 2 || min === null || max === null || avg === null) {
      return null;
    }

    return { min, max, avg };
  }

  private formatNumber(value: number | null): string {
    return value === null ? '—' : this.numberFormat.format(value);
  }
}
