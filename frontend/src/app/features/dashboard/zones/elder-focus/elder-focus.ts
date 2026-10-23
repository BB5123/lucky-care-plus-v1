import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { DashboardPayload } from '../../../../core/dashboard/dashboard.models';
import { translateAlertMessage } from '../alert-messages';

interface ElderAction {
  readonly title: string;
  readonly detail: string;
  readonly label: string;
  readonly link: string;
}

/**
 * Elder mode (blueprint MOBILE_DESIGN §6): one action at a time, one primary
 * full-width button, type ≥ 20px, and no charts or tables. The component is
 * only mounted when the shell's elder-mode switch is on.
 */
@Component({
  selector: 'app-elder-focus',
  imports: [RouterLink],
  templateUrl: './elder-focus.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ElderFocus {
  readonly dashboard = input<DashboardPayload | null>(null);
  readonly loading = input(false);
  readonly error = input<string | null>(null);

  readonly retry = output<void>();

  protected readonly action = computed<ElderAction | null>(() => {
    const data = this.dashboard();

    if (data === null) {
      return null;
    }

    const alerts = data.alerts.visible ? data.alerts.items : [];
    const alert = alerts.find((item) => item.level === 'critical') ?? alerts[0];

    if (alert !== undefined) {
      return {
        title: translateAlertMessage(alert.message_key),
        detail: 'เปิดรายการเพื่อดูรายละเอียด',
        label: 'ดูงานที่ต้องทำ',
        link: '/tasks',
      };
    }

    const tasks = data.tasks_today.visible ? data.tasks_today.items : [];
    const task = tasks.find((item) => item.status !== 'completed' && item.status !== 'cancelled');

    if (task !== undefined) {
      return {
        title: task.title,
        detail: `กำหนด ${this.formatDateTime(task.due_at)}`,
        label: 'เปิดงานดูแล',
        link: '/tasks',
      };
    }

    const appointment = data.next_appointment;

    if (appointment.visible && appointment.title !== null) {
      return {
        title: `นัดหมาย: ${appointment.title}`,
        detail: this.formatDateTime(appointment.scheduled_at),
        label: 'ดูนัดหมาย',
        link: '/appointments',
      };
    }

    return {
      title: 'วันนี้ยังไม่มีสิ่งที่ต้องทำ',
      detail: 'คุณสามารถพักผ่อนได้',
      label: 'ดูงานดูแล',
      link: '/tasks',
    };
  });

  private readonly dateTimeFormat = new Intl.DateTimeFormat('th-TH', {
    dateStyle: 'medium',
    timeStyle: 'short',
  });

  private formatDateTime(value: string | null): string {
    if (value === null) {
      return 'ไม่ระบุเวลา';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? 'ไม่ระบุเวลา' : this.dateTimeFormat.format(date);
  }
}
