/**
 * Typed mirror of the appointment API
 * (`.../workspaces/{workspace}/profiles/{profile}/appointments`).
 *
 * An appointment is coordination data, not a medical record (APT-001). The
 * parser keeps the prepared-document order exactly as the server returned it:
 * the checklist index is the identity used by the toggle endpoint, so
 * re-sorting it client-side would toggle the wrong item.
 *
 * The state machine below mirrors `AppointmentStateMachine` for presentation
 * only. Every transition is re-checked by the server; a 409 is rendered with
 * the server's Thai domain sentence.
 */

export const APPOINTMENT_STATUSES = [
  'planned',
  'confirmed',
  'attended',
  'rescheduled',
  'cancelled',
  'missed',
  'needs_followup',
] as const;

export type AppointmentStatus = (typeof APPOINTMENT_STATUSES)[number];

export const APPOINTMENT_ACTIONS = ['confirm', 'attended', 'reschedule', 'cancel'] as const;

export type AppointmentAction = (typeof APPOINTMENT_ACTIONS)[number];

export type AppointmentFormMode = 'create' | 'edit' | 'reschedule';

export type AppointmentStatusStepState = 'done' | 'current' | 'upcoming';

export interface PreparedDocument {
  readonly label: string;
  readonly done: boolean;
}

export interface Appointment {
  readonly id: string;
  readonly profile_id: string;
  readonly profile_display_name: string | null;
  readonly title: string;
  readonly provider_name: string | null;
  readonly facility: string | null;
  readonly purpose: string | null;
  readonly location: string | null;
  readonly scheduled_at: string | null;
  readonly duration_minutes: number | null;
  readonly status: string;
  readonly cancelled_reason: string | null;
  readonly rescheduled_from_id: string | null;
  readonly transported_by_task_id: string | null;
  readonly prepared_documents: readonly PreparedDocument[];
  readonly follow_up_task_id: string | null;
  readonly version: number;
  readonly source: string;
  readonly created_by: string | number | null;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface AppointmentFilters {
  readonly status?: AppointmentStatus | null;
  readonly from?: string | null;
  readonly to?: string | null;
  readonly upcoming?: boolean;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface AppointmentPage {
  readonly items: readonly Appointment[];
  readonly nextCursor: string | null;
  readonly limit: number;
}

/**
 * Create/PATCH/reschedule body. Optional free-text fields are omitted when the
 * user left them blank; the form must never send `''` where the contract says
 * `nullable`.
 */
export interface AppointmentWriteBody {
  readonly title: string;
  readonly scheduled_at: string;
  readonly provider_name?: string;
  readonly facility?: string;
  readonly purpose?: string;
  readonly location?: string;
  readonly duration_minutes?: number;
  readonly prepared_documents?: readonly PreparedDocument[];
}

/**
 * PATCH body. `status` is the only lifecycle field a PATCH may carry; the
 * dedicated `attended` and `cancel` endpoints exist because they own side
 * effects the server refuses to skip.
 */
export interface AppointmentUpdateBody {
  readonly title?: string;
  readonly scheduled_at?: string;
  readonly provider_name?: string;
  readonly facility?: string;
  readonly purpose?: string;
  readonly location?: string;
  readonly duration_minutes?: number;
  readonly prepared_documents?: readonly PreparedDocument[];
  readonly status?: AppointmentStatus;
}

/** Raw form values before normalisation. */
export interface AppointmentDraft {
  readonly title: string;
  readonly provider_name: string;
  readonly facility: string;
  readonly purpose: string;
  readonly location: string;
  readonly scheduled_at: string;
  readonly duration_minutes: string;
  readonly prepared_documents: readonly PreparedDocument[];
}

export interface AppointmentStatusStep {
  readonly key: string;
  readonly label: string;
  readonly state: AppointmentStatusStepState;
}

export interface AppointmentSplit {
  readonly upcoming: readonly Appointment[];
  readonly past: readonly Appointment[];
}

const DAY_IN_MS = 24 * 60 * 60 * 1000;

const EMPTY_FIELD_ERRORS: Readonly<Record<string, string>> = {};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readObject(value: unknown): Record<string, unknown> {
  return isRecord(value) ? value : {};
}

function readArray(value: unknown): readonly unknown[] {
  return Array.isArray(value) ? value : [];
}

function readString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function readUserId(value: unknown): string | number | null {
  if (typeof value === 'string') {
    return value;
  }

  return readNumber(value);
}

function parseTimestamp(value: string | null): number | null {
  if (value === null || value === '') {
    return null;
  }

  const timestamp = Date.parse(value);

  return Number.isNaN(timestamp) ? null : timestamp;
}

export function isAppointmentStatus(value: string): value is AppointmentStatus {
  return (APPOINTMENT_STATUSES as readonly string[]).includes(value);
}

export function isAppointmentAction(value: string): value is AppointmentAction {
  return (APPOINTMENT_ACTIONS as readonly string[]).includes(value);
}

export function appointmentStatusLabel(status: string): string {
  switch (status) {
    case 'planned':
      return 'วางแผนแล้ว';
    case 'confirmed':
      return 'ยืนยันแล้ว';
    case 'attended':
      return 'เข้ารับบริการแล้ว';
    case 'rescheduled':
      return 'เลื่อนนัดแล้ว';
    case 'cancelled':
      return 'ยกเลิกแล้ว';
    case 'missed':
      return 'ไม่ได้เข้ารับบริการ';
    case 'needs_followup':
      return 'ต้องติดตามผล';
    default:
      return 'ไม่ทราบสถานะ';
  }
}

export function appointmentActionLabel(action: AppointmentAction): string {
  switch (action) {
    case 'confirm':
      return 'ยืนยัน';
    case 'attended':
      return 'บันทึกว่าไปแล้ว';
    case 'reschedule':
      return 'เลื่อน';
    case 'cancel':
      return 'ยกเลิก';
    default:
      return 'ดำเนินการ';
  }
}

/**
 * Actions offered for a status. Mirrors `AppointmentStateMachine`:
 * planned → confirmed | rescheduled | cancelled; confirmed → attended |
 * rescheduled | cancelled. `attended` has no follow-up action because the
 * server already created the care task.
 */
export function availableAppointmentActions(status: string): readonly AppointmentAction[] {
  switch (status) {
    case 'planned':
      return ['confirm', 'reschedule', 'cancel'];
    case 'confirmed':
      return ['attended', 'reschedule', 'cancel'];
    default:
      return [];
  }
}

export function canEditAppointment(status: string): boolean {
  return status === 'planned' || status === 'confirmed';
}

export function appointmentDocumentProgress(appointment: Appointment): string {
  const total = appointment.prepared_documents.length;
  const done = appointment.prepared_documents.filter((document) => document.done).length;

  return `เอกสาร ${done}/${total}`;
}

/** Calendar-day distance, so "tomorrow" is never "in 23 hours". */
function startOfLocalDay(timestamp: number): number {
  const date = new Date(timestamp);

  date.setHours(0, 0, 0, 0);

  return date.getTime();
}

export function appointmentRelativeLabel(
  scheduledAt: string | null,
  now: Date = new Date(),
): string {
  const timestamp = parseTimestamp(scheduledAt);

  if (timestamp === null) {
    return 'ไม่ระบุวัน';
  }

  const days = Math.round(
    (startOfLocalDay(timestamp) - startOfLocalDay(now.getTime())) / DAY_IN_MS,
  );

  if (days === 0) {
    return 'วันนี้';
  }

  if (days === 1) {
    return 'พรุ่งนี้';
  }

  if (days > 1) {
    return `อีก ${days} วัน`;
  }

  if (days === -1) {
    return 'เมื่อวาน';
  }

  return `${Math.abs(days)} วันที่แล้ว`;
}

/**
 * Upcoming means still actionable: a future `planned`/`confirmed` row. An
 * attended or cancelled row is history even when its timestamp is in the
 * future, which is why status is checked as well as the clock.
 */
export function isAppointmentUpcoming(appointment: Appointment, now: Date = new Date()): boolean {
  if (appointment.status !== 'planned' && appointment.status !== 'confirmed') {
    return false;
  }

  const timestamp = parseTimestamp(appointment.scheduled_at);

  return timestamp !== null && timestamp >= now.getTime();
}

export function splitAppointments(
  appointments: readonly Appointment[],
  now: Date = new Date(),
): AppointmentSplit {
  const upcoming: Appointment[] = [];
  const past: Appointment[] = [];

  for (const appointment of appointments) {
    if (isAppointmentUpcoming(appointment, now)) {
      upcoming.push(appointment);
    } else {
      past.push(appointment);
    }
  }

  const timestampOf = (appointment: Appointment): number =>
    parseTimestamp(appointment.scheduled_at) ?? 0;

  upcoming.sort((left, right) => timestampOf(left) - timestampOf(right));
  past.sort((left, right) => timestampOf(right) - timestampOf(left));

  return { upcoming, past };
}

export function appointmentStatusTimeline(status: string): readonly AppointmentStatusStep[] {
  if (status === 'planned') {
    return [
      { key: 'planned', label: 'วางแผน', state: 'current' },
      { key: 'confirmed', label: 'ยืนยัน', state: 'upcoming' },
      { key: 'attended', label: 'เข้ารับบริการ', state: 'upcoming' },
    ];
  }

  if (status === 'confirmed') {
    return [
      { key: 'planned', label: 'วางแผน', state: 'done' },
      { key: 'confirmed', label: 'ยืนยัน', state: 'current' },
      { key: 'attended', label: 'เข้ารับบริการ', state: 'upcoming' },
    ];
  }

  if (status === 'attended' || status === 'needs_followup') {
    return [
      { key: 'planned', label: 'วางแผน', state: 'done' },
      { key: 'confirmed', label: 'ยืนยัน', state: 'done' },
      { key: 'attended', label: 'เข้ารับบริการ', state: 'done' },
      ...(status === 'needs_followup'
        ? [{ key: status, label: appointmentStatusLabel(status), state: 'current' as const }]
        : []),
    ];
  }

  return [{ key: status, label: appointmentStatusLabel(status), state: 'current' }];
}

/** `datetime-local` value for the form; the API value is an ISO instant. */
export function toLocalDateTimeInput(value: string | null): string {
  const timestamp = parseTimestamp(value);

  if (timestamp === null) {
    return '';
  }

  const date = new Date(timestamp);
  const pad = (part: number): string => String(part).padStart(2, '0');

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(
    date.getHours(),
  )}:${pad(date.getMinutes())}`;
}

/**
 * The picker emits `YYYY-MM-DDTHH:mm` in the caregiver's local time. Laravel's
 * `date` rule accepts it, but sending an explicit instant avoids relying on
 * the server's timezone when the family travels.
 */
export function normalizeScheduledAt(value: string): string {
  const trimmed = value.trim();

  if (trimmed === '') {
    return '';
  }

  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(trimmed) || /^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
    const local = new Date(trimmed.length === 10 ? `${trimmed}T00:00:00` : trimmed);

    return Number.isNaN(local.getTime()) ? trimmed : local.toISOString();
  }

  return trimmed;
}

function optionalString(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === '' ? null : trimmed;
}

/** Builds the create/PATCH body without empty optional strings. */
export function buildAppointmentBody(draft: AppointmentDraft): AppointmentWriteBody {
  const body: {
    title: string;
    scheduled_at: string;
    provider_name?: string;
    facility?: string;
    purpose?: string;
    location?: string;
    duration_minutes?: number;
    prepared_documents?: readonly PreparedDocument[];
  } = {
    title: draft.title.trim(),
    scheduled_at: normalizeScheduledAt(draft.scheduled_at),
  };

  const provider = optionalString(draft.provider_name);
  const facility = optionalString(draft.facility);
  const purpose = optionalString(draft.purpose);
  const location = optionalString(draft.location);

  if (provider !== null) {
    body.provider_name = provider;
  }

  if (facility !== null) {
    body.facility = facility;
  }

  if (purpose !== null) {
    body.purpose = purpose;
  }

  if (location !== null) {
    body.location = location;
  }

  const duration = Number(draft.duration_minutes.trim());

  if (draft.duration_minutes.trim() !== '' && Number.isFinite(duration) && duration > 0) {
    body.duration_minutes = Math.trunc(duration);
  }

  body.prepared_documents = draft.prepared_documents
    .map((document) => ({ label: document.label.trim(), done: document.done }))
    .filter((document) => document.label !== '');

  return body;
}

export function validateAppointmentDraft(
  draft: AppointmentDraft,
): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};

  if (draft.title.trim() === '') {
    errors['title'] = 'กรุณากรอกชื่อนัดหมาย';
  }

  if (draft.title.trim().length > 160) {
    errors['title'] = 'ชื่อนัดหมายต้องไม่เกิน 160 ตัวอักษร';
  }

  if (draft.scheduled_at.trim() === '') {
    errors['scheduled_at'] = 'กรุณาเลือกวันที่และเวลา';
  } else if (Number.isNaN(Date.parse(draft.scheduled_at.trim()))) {
    errors['scheduled_at'] = 'วันที่และเวลาไม่ถูกต้อง';
  }

  const duration = draft.duration_minutes.trim();

  if (duration !== '') {
    const value = Number(duration);

    if (!Number.isInteger(value) || value < 5 || value > 480) {
      errors['duration_minutes'] = 'ระยะเวลาต้องอยู่ระหว่าง 5–480 นาที';
    }
  }

  if (draft.prepared_documents.some((document) => document.label.trim() === '')) {
    errors['documents'] = 'กรุณากรอกชื่อเอกสารให้ครบทุกรายการ';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function parsePreparedDocument(value: unknown): PreparedDocument | null {
  const raw = readObject(value);
  const label = readString(raw['label']);

  if (label === null || label.trim() === '') {
    return null;
  }

  return {
    label,
    done: raw['done'] === true,
  };
}

export function parseAppointment(value: unknown): Appointment | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const title = readString(raw['title']);

  if (id === null || id === '' || title === null) {
    return null;
  }

  return {
    id,
    profile_id: readString(raw['profile_id']) ?? '',
    profile_display_name: readString(raw['profile_display_name']),
    title,
    provider_name: readString(raw['provider_name']),
    facility: readString(raw['facility']),
    purpose: readString(raw['purpose']),
    location: readString(raw['location']),
    scheduled_at: readString(raw['scheduled_at']),
    duration_minutes: readNumber(raw['duration_minutes']),
    status: readString(raw['status']) ?? 'planned',
    cancelled_reason: readString(raw['cancelled_reason']),
    rescheduled_from_id: readString(raw['rescheduled_from_id']),
    transported_by_task_id: readString(raw['transported_by_task_id']),
    prepared_documents: readArray(raw['prepared_documents'])
      .map((document) => parsePreparedDocument(document))
      .filter((document): document is PreparedDocument => document !== null),
    follow_up_task_id: readString(raw['follow_up_task_id']),
    version: readNumber(raw['version']) ?? 0,
    source: readString(raw['source']) ?? 'manual',
    created_by: readUserId(raw['created_by']),
    created_at: readString(raw['created_at']),
    updated_at: readString(raw['updated_at']),
  };
}

export function parseAppointmentPage(value: unknown): AppointmentPage {
  const raw = readObject(value);
  const meta = readObject(raw['meta']);
  const items = readArray(raw['data'])
    .map((item) => parseAppointment(item))
    .filter((item): item is Appointment => item !== null);

  return {
    items,
    nextCursor: readString(meta['next_cursor']),
    limit: readNumber(meta['limit']) ?? 20,
  };
}
