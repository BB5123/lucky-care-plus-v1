import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NextAppointmentZone } from '../../../../core/dashboard/dashboard.models';

/**
 * Z5 — Next appointment. The date is formatted with `Intl.DateTimeFormat(
 * 'th-TH')`, which renders the Buddhist-era year. "เตรียมเอกสาร" is a
 * placeholder destination for the Phase 2 document flow.
 */
@Component({
  selector: 'app-next-appointment',
  imports: [RouterLink],
  templateUrl: './next-appointment.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NextAppointment {
  readonly zone = input<NextAppointmentZone | null>(null);
  readonly loading = input(false);
  readonly error = input<string | null>(null);

  readonly retry = output<void>();

  private readonly dateTimeFormat = new Intl.DateTimeFormat('th-TH', {
    dateStyle: 'long',
    timeStyle: 'short',
  });

  protected formatDateTime(value: string | null): string {
    if (value === null) {
      return 'ไม่ระบุวันเวลา';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? 'ไม่ระบุวันเวลา' : this.dateTimeFormat.format(date);
  }

  protected statusLabel(status: string | null): string {
    switch (status) {
      case 'planned':
        return 'รอยืนยัน';
      case 'confirmed':
        return 'ยืนยันแล้ว';
      case 'attended':
        return 'เข้ารับบริการแล้ว';
      case 'rescheduled':
        return 'เลื่อนนัด';
      case 'cancelled':
        return 'ยกเลิก';
      case 'missed':
        return 'ไม่มาตามนัด';
      case 'needs_followup':
        return 'ต้องติดตาม';
      default:
        return 'ไม่ทราบสถานะ';
    }
  }
}
