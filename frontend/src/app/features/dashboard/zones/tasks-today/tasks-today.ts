import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TasksTodayZone } from '../../../../core/dashboard/dashboard.models';

/**
 * Z3 — Today's care tasks. The server sends the safe P1.3 card projection only
 * (title, due time, status); the client never asks for descriptions or health
 * values.
 */
@Component({
  selector: 'app-tasks-today',
  imports: [RouterLink],
  templateUrl: './tasks-today.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TasksToday {
  readonly zone = input<TasksTodayZone | null>(null);
  readonly loading = input(false);
  readonly error = input<string | null>(null);

  readonly retry = output<void>();

  protected readonly skeletons = [1, 2, 3];

  private readonly dueTimeFormat = new Intl.DateTimeFormat('th-TH', {
    hour: '2-digit',
    minute: '2-digit',
  });

  protected progressPercent(zone: TasksTodayZone): number {
    if (zone.total <= 0) {
      return 0;
    }

    return Math.min(100, Math.max(0, Math.round((zone.done / zone.total) * 100)));
  }

  protected dueTime(value: string | null): string {
    if (value === null) {
      return 'ไม่ระบุเวลา';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? 'ไม่ระบุเวลา' : this.dueTimeFormat.format(date);
  }

  protected statusLabel(status: string): string {
    switch (status) {
      case 'scheduled':
        return 'กำหนดไว้';
      case 'open':
        return 'รอดำเนินการ';
      case 'assigned':
        return 'มอบหมายแล้ว';
      case 'accepted':
        return 'รับงานแล้ว';
      case 'in_progress':
        return 'กำลังทำ';
      case 'blocked':
        return 'ติดปัญหา';
      case 'completed':
        return 'เสร็จแล้ว';
      case 'cancelled':
        return 'ยกเลิก';
      default:
        return 'ไม่ทราบสถานะ';
    }
  }
}
