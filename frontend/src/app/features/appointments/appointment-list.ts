import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  Appointment,
  appointmentDocumentProgress,
  appointmentRelativeLabel,
  appointmentStatusLabel,
  splitAppointments,
} from '../../core/appointments/appointment.models';
import { AppointmentService } from '../../core/appointments/appointment.service';
import { formatThaiDateTime } from '../../core/health-record/health-record.format';

/** Upcoming appointments first (soonest at top), then past appointments. */
@Component({
  selector: 'app-appointment-list',
  imports: [RouterLink],
  templateUrl: './appointment-list.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentList {
  private readonly appointments = inject(AppointmentService);

  readonly profileId = input.required<string>();

  protected readonly items = this.appointments.appointments;
  protected readonly loading = this.appointments.loading;
  protected readonly error = this.appointments.error;
  protected readonly nextCursor = this.appointments.nextCursor;

  private readonly now = signal(Date.now());

  protected readonly groups = computed(() => splitAppointments(this.items(), new Date(this.now())));

  constructor() {
    effect(() => {
      const profileId = this.profileId();

      if (profileId === '') {
        return;
      }

      untracked(() => {
        void this.load();
      });
    });
  }

  protected load(): Promise<void> {
    this.now.set(Date.now());

    return this.appointments.load(this.profileId(), { limit: 50 });
  }

  protected loadMore(): void {
    const cursor = this.nextCursor();

    if (cursor === null) {
      return;
    }

    void this.appointments.load(this.profileId(), { limit: 50, cursor }, true);
  }

  protected dateTimeLabel(item: Appointment): string {
    return formatThaiDateTime(item.scheduled_at);
  }

  protected relativeLabel(item: Appointment): string {
    return appointmentRelativeLabel(item.scheduled_at, new Date(this.now()));
  }

  protected statusLabel(item: Appointment): string {
    return appointmentStatusLabel(item.status);
  }

  protected documentProgress(item: Appointment): string {
    return appointmentDocumentProgress(item);
  }

  protected providerLine(item: Appointment): string {
    const provider = item.provider_name ?? 'ไม่ระบุผู้ให้บริการ';

    return item.facility === null ? provider : `${provider} · ${item.facility}`;
  }
}
