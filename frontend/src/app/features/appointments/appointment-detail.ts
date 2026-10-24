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
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { httpStatus, toUserMessage } from '../../core/api/problem-details';
import {
  Appointment,
  AppointmentAction,
  AppointmentFormMode,
  AppointmentStatus,
  appointmentActionLabel,
  appointmentDocumentProgress,
  appointmentRelativeLabel,
  appointmentStatusLabel,
  appointmentStatusTimeline,
  availableAppointmentActions,
  canEditAppointment,
} from '../../core/appointments/appointment.models';
import { AppointmentService } from '../../core/appointments/appointment.service';
import { formatThaiDateTime } from '../../core/health-record/health-record.format';
import { AppointmentForm } from './appointment-form';

/**
 * Appointment detail.
 *
 * The action buttons only *offer* transitions the state machine allows; the
 * server remains the authority. A 409 keeps the server's Thai domain sentence,
 * while a 412 replaces the action area with "ข้อมูลถูกแก้ไขโดยคนอื่น" and a
 * reload action. `attended` is idempotent server-side and the submit guard
 * here prevents a second create-task spinner while the first call is in
 * flight.
 */
@Component({
  selector: 'app-appointment-detail',
  imports: [RouterLink, ReactiveFormsModule, AppointmentForm],
  templateUrl: './appointment-detail.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentDetail {
  private readonly appointments = inject(AppointmentService);
  private readonly formBuilder = inject(NonNullableFormBuilder);
  private readonly router = inject(Router);

  readonly profileId = input.required<string>();
  readonly appointmentId = input.required<string>();

  protected readonly appointment = this.appointments.detail;
  protected readonly loading = this.appointments.detailLoading;
  protected readonly error = this.appointments.detailError;
  protected readonly errorStatus = this.appointments.detailErrorStatus;

  protected readonly submitting = signal(false);
  protected readonly toggling = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly announcement = signal<string | null>(null);
  protected readonly cancelOpen = signal(false);
  protected readonly reasonError = signal<string | null>(null);
  protected readonly formMode = signal<AppointmentFormMode | null>(null);

  protected readonly cancelForm = this.formBuilder.group({
    reason: [''],
  });

  protected readonly actions = computed<readonly AppointmentAction[]>(() => {
    const appointment = this.appointment();

    return appointment === null ? [] : availableAppointmentActions(appointment.status);
  });

  protected readonly canEdit = computed(() => {
    const appointment = this.appointment();

    return appointment !== null && canEditAppointment(appointment.status);
  });

  protected readonly steps = computed(() => {
    const appointment = this.appointment();

    return appointment === null ? [] : appointmentStatusTimeline(appointment.status);
  });

  protected readonly followUpTaskId = computed(() => this.appointment()?.follow_up_task_id ?? null);

  constructor() {
    effect(() => {
      const profileId = this.profileId();
      const appointmentId = this.appointmentId();

      untracked(() => {
        this.formMode.set(null);
        this.cancelOpen.set(false);
        this.formError.set(null);
        this.conflict.set(false);
        this.announcement.set(null);
        void this.appointments.loadDetail(profileId, appointmentId);
      });
    });
  }

  protected statusLabel(status: string): string {
    return appointmentStatusLabel(status);
  }

  protected actionLabel(action: AppointmentAction): string {
    return appointmentActionLabel(action);
  }

  protected dateTimeLabel(value: string | null): string {
    return formatThaiDateTime(value);
  }

  protected relativeLabel(value: string | null): string {
    return appointmentRelativeLabel(value);
  }

  protected documentProgress(appointment: Appointment): string {
    return appointmentDocumentProgress(appointment);
  }

  protected durationLabel(value: number | null): string {
    return value === null ? 'ไม่กำหนดเวลา' : `${value} นาที`;
  }

  protected chooseAction(action: AppointmentAction): void {
    this.formError.set(null);
    this.conflict.set(false);
    this.reasonError.set(null);

    switch (action) {
      case 'confirm':
        void this.runStatusUpdate('confirmed');
        return;
      case 'attended':
        void this.runAttended();
        return;
      case 'cancel':
        this.cancelForm.reset({ reason: '' });
        this.cancelOpen.set(true);
        return;
      case 'reschedule':
        this.openForm('reschedule');
        return;
    }
  }

  protected openEdit(): void {
    this.openForm('edit');
  }

  protected closeForm(): void {
    this.formMode.set(null);
  }

  protected closeCancel(): void {
    this.cancelOpen.set(false);
    this.reasonError.set(null);
  }

  protected async submitCancel(): Promise<void> {
    const appointment = this.appointment();

    if (appointment === null || this.submitting()) {
      return;
    }

    const reason = this.cancelForm.getRawValue().reason.trim();

    if (reason.length < 5) {
      this.reasonError.set('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
      return;
    }

    if (reason.length > 1000) {
      this.reasonError.set('เหตุผลต้องไม่เกิน 1,000 ตัวอักษร');
      return;
    }

    this.reasonError.set(null);
    this.formError.set(null);
    this.conflict.set(false);
    this.submitting.set(true);

    try {
      await this.appointments.cancel(this.profileId(), appointment, reason);
      this.cancelOpen.set(false);
      this.announce('ยกเลิกนัดหมายแล้ว');
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.cancelOpen.set(false);
        this.conflict.set(true);
        return;
      }

      // 409 is the domain rule; `toUserMessage` carries the server's safe Thai
      // sentence and is never replaced by a generic toast.
      this.formError.set(toUserMessage(error));
    } finally {
      this.submitting.set(false);
    }
  }

  protected async toggleDocument(index: number, event: Event): Promise<void> {
    const appointment = this.appointment();
    const target = event.target;

    if (
      appointment === null ||
      this.toggling() ||
      this.submitting() ||
      !(target instanceof HTMLInputElement)
    ) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);
    this.toggling.set(true);

    try {
      await this.appointments.toggleDocument(this.profileId(), appointment, index, target.checked);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        return;
      }

      this.formError.set(toUserMessage(error));
    } finally {
      this.toggling.set(false);
    }
  }

  protected onFormSaved(saved: Appointment): void {
    const wasReschedule = this.formMode() === 'reschedule';

    this.formMode.set(null);
    this.announce(wasReschedule ? 'เลื่อนนัดหมายแล้ว' : 'บันทึกนัดหมายแล้ว');

    if (wasReschedule) {
      void this.router.navigate(['/appointments', saved.id], {
        queryParams: { profile_id: saved.profile_id === '' ? this.profileId() : saved.profile_id },
      });
    }
  }

  protected reload(): void {
    this.formMode.set(null);
    this.cancelOpen.set(false);
    this.formError.set(null);
    this.conflict.set(false);
    this.announcement.set(null);
    void this.appointments.loadDetail(this.profileId(), this.appointmentId());
  }

  private openForm(mode: AppointmentFormMode): void {
    this.formError.set(null);
    this.conflict.set(false);
    this.formMode.set(mode);
  }

  private async runStatusUpdate(status: AppointmentStatus): Promise<void> {
    const appointment = this.appointment();

    if (appointment === null || this.submitting()) {
      return;
    }

    this.submitting.set(true);

    try {
      await this.appointments.update(this.profileId(), appointment, { status });
      this.announce('ยืนยันนัดหมายแล้ว');
    } catch (error) {
      this.handleActionError(error);
    } finally {
      this.submitting.set(false);
    }
  }

  private async runAttended(): Promise<void> {
    const appointment = this.appointment();

    if (appointment === null || this.submitting()) {
      return;
    }

    // The button is disabled while `submitting`; this guard also covers a
    // second click that lands before Angular repaints the disabled state.
    this.submitting.set(true);

    try {
      await this.appointments.markAttended(this.profileId(), appointment);
      this.announce('บันทึกว่าเข้ารับบริการแล้ว');
    } catch (error) {
      this.handleActionError(error);
    } finally {
      this.submitting.set(false);
    }
  }

  private handleActionError(error: unknown): void {
    if (httpStatus(error) === 412) {
      this.conflict.set(true);
      return;
    }

    this.formError.set(toUserMessage(error));
  }

  private announce(message: string): void {
    this.announcement.set('');
    queueMicrotask(() => this.announcement.set(message));
  }
}
