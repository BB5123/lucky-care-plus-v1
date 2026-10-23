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
import {
  FormControl,
  FormGroup,
  NonNullableFormBuilder,
  ReactiveFormsModule,
} from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import {
  Appointment,
  AppointmentDraft,
  AppointmentFormMode,
  PreparedDocument,
  buildAppointmentBody,
  toLocalDateTimeInput,
  validateAppointmentDraft,
} from '../../core/appointments/appointment.models';
import { AppointmentService } from '../../core/appointments/appointment.service';

type DocumentGroup = FormGroup<{
  label: FormControl<string>;
  done: FormControl<boolean>;
}>;

/**
 * Create, edit and reschedule form.
 *
 * The fields marked "visible only to people with access" are encrypted at rest
 * by the server and are never copied into audit metadata. On reschedule the
 * form keeps the source row untouched: the service POSTs a replacement row
 * carrying `rescheduled_from_id` (APT-003), so the move survives in history.
 */
@Component({
  selector: 'app-appointment-form',
  imports: [ReactiveFormsModule],
  templateUrl: './appointment-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentForm {
  private readonly appointments = inject(AppointmentService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly profileId = input.required<string>();
  readonly mode = input<AppointmentFormMode>('create');
  readonly editing = input<Appointment | null>(null);
  readonly source = input<Appointment | null>(null);

  readonly saved = output<Appointment>();
  readonly cancelled = output<void>();
  readonly reloadRequested = output<void>();

  protected readonly documents = this.formBuilder.array<DocumentGroup>([]);

  protected readonly form = this.formBuilder.group({
    title: [''],
    provider_name: [''],
    facility: [''],
    scheduled_at: [''],
    duration_minutes: [''],
    purpose: [''],
    location: [''],
    documents: this.documents,
  });

  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  constructor() {
    effect(() => {
      const mode = this.mode();
      const editing = this.editing();
      const source = this.source();

      untracked(() => {
        this.fill(mode, editing, source);
      });
    });
  }

  protected formTitle(): string {
    switch (this.mode()) {
      case 'edit':
        return 'แก้ไขนัดหมาย';
      case 'reschedule':
        return 'เลื่อนนัดหมาย';
      default:
        return 'เพิ่มนัดหมาย';
    }
  }

  protected submitLabel(): string {
    return this.mode() === 'reschedule' ? 'บันทึกการเลื่อนนัด' : 'บันทึก';
  }

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected addDocument(): void {
    this.documents.push(this.documentGroup({ label: '', done: false }));
  }

  protected removeDocument(index: number): void {
    this.documents.removeAt(index);
  }

  protected async submit(): Promise<void> {
    if (this.submitting()) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);

    const raw = this.form.getRawValue();
    const draft: AppointmentDraft = {
      title: raw.title,
      provider_name: raw.provider_name,
      facility: raw.facility,
      purpose: raw.purpose,
      location: raw.location,
      scheduled_at: raw.scheduled_at,
      duration_minutes: raw.duration_minutes,
      prepared_documents: raw.documents.map((document): PreparedDocument => ({
        label: document.label,
        done: document.done,
      })),
    };
    const clientErrors = validateAppointmentDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      this.form.markAllAsTouched();
      return;
    }

    this.fieldErrors.set({});
    this.submitting.set(true);

    try {
      const body = buildAppointmentBody(draft);
      const mode = this.mode();
      const editing = this.editing();
      const source = this.source();
      let saved: Appointment;

      if (mode === 'reschedule') {
        if (source === null) {
          this.formError.set('ไม่พบนัดหมายต้นทางสำหรับการเลื่อนนัด');
          return;
        }

        saved = await this.appointments.reschedule(this.profileId(), source, body);
      } else if (mode === 'edit') {
        if (editing === null) {
          this.formError.set('ไม่พบนัดหมายที่ต้องการแก้ไข');
          return;
        }

        saved = await this.appointments.update(this.profileId(), editing, body);
      } else {
        saved = await this.appointments.create(this.profileId(), body);
      }

      this.saved.emit(saved);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        this.fieldErrors.set({});
        this.formError.set(null);
        return;
      }

      const serverErrors = readFieldErrors(error);

      if (Object.keys(serverErrors).length > 0) {
        this.fieldErrors.set(this.normalizeFieldErrors(serverErrors));
      } else {
        // 409 keeps the server's Thai domain sentence; it is never replaced by
        // a generic toast.
        this.formError.set(toUserMessage(error));
      }
    } finally {
      this.submitting.set(false);
    }
  }

  private fill(
    mode: AppointmentFormMode,
    editing: Appointment | null,
    source: Appointment | null,
  ): void {
    const appointment = mode === 'reschedule' ? source : editing;

    this.documents.clear();

    for (const document of appointment?.prepared_documents ?? []) {
      this.documents.push(this.documentGroup(document));
    }

    this.form.reset({
      title: appointment?.title ?? '',
      provider_name: appointment?.provider_name ?? '',
      facility: appointment?.facility ?? '',
      scheduled_at: toLocalDateTimeInput(appointment?.scheduled_at ?? null),
      duration_minutes:
        appointment?.duration_minutes === null || appointment?.duration_minutes === undefined
          ? ''
          : String(appointment.duration_minutes),
      purpose: appointment?.purpose ?? '',
      location: appointment?.location ?? '',
    });
    this.fieldErrors.set({});
    this.formError.set(null);
    this.conflict.set(false);
  }

  private documentGroup(document: PreparedDocument): DocumentGroup {
    return this.formBuilder.group({
      label: [document.label],
      done: [document.done],
    });
  }

  private normalizeFieldErrors(
    errors: Readonly<Record<string, string>>,
  ): Readonly<Record<string, string>> {
    const normalized: Record<string, string> = { ...errors };

    for (const [field, message] of Object.entries(errors)) {
      if (field.startsWith('prepared_documents') && normalized['documents'] === undefined) {
        normalized['documents'] = message;
      }
    }

    return normalized;
  }
}
