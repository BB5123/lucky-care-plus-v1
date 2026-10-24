/**
 * Typed mirror of the medication API (`.../profiles/{profile}/medication-plans`,
 * `medication-records`, `medication-adherence`).
 *
 * The backend stays the authority on ranges and domain rules. This module
 * narrows untrusted JSON, exposes the same limits the Laravel requests and
 * `MedicationPlanRules` use, and never derives adherence numbers: those are
 * rendered exactly as the server computed them.
 */

export const MEDICATION_FORMS = [
  'tablet',
  'capsule',
  'liquid',
  'injection',
  'patch',
  'inhaler',
  'other',
] as const;

export type MedicationForm = (typeof MEDICATION_FORMS)[number];

export const MEDICATION_ROUTES = ['oral', 'topical', 'injection', 'inhalation', 'other'] as const;

export type MedicationRoute = (typeof MEDICATION_ROUTES)[number];

export const MEDICATION_PLAN_STATUSES = ['active', 'discontinued'] as const;

export type MedicationPlanStatus = (typeof MEDICATION_PLAN_STATUSES)[number];

export const MEDICATION_RECORD_STATUSES = ['taken', 'skipped'] as const;

export type MedicationRecordStatus = (typeof MEDICATION_RECORD_STATUSES)[number];

export const DOSES_PER_DAY_MIN = 1;
export const DOSES_PER_DAY_MAX = 12;
export const DISCONTINUE_REASON_MIN_LENGTH = 5;
export const DISCONTINUE_REASON_MAX_LENGTH = 1000;

const TIME_PATTERN = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

export interface MedicationPlan {
  readonly id: string;
  readonly name: string;
  readonly dosage: string | null;
  readonly form: string;
  readonly route: string | null;
  readonly doses_per_day: number;
  readonly schedule_times: readonly string[];
  readonly starts_on: string | null;
  readonly ends_on: string | null;
  readonly prescribed_by: string | null;
  readonly instructions: string | null;
  readonly status: string;
  readonly discontinued_reason: string | null;
  readonly discontinued_at: string | null;
  readonly source: string;
  readonly recorded_by: string | null;
  readonly version: number;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface MedicationRecord {
  readonly id: string;
  readonly plan_id: string;
  readonly status: string;
  readonly scheduled_for: string | null;
  readonly recorded_at: string | null;
  readonly note: string | null;
  readonly source: string;
  readonly recorded_by: string | null;
  readonly version: number;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface AdherenceDay {
  readonly date: string;
  readonly expected: number;
  readonly taken: number;
  readonly skipped: number;
  readonly missed: number;
  readonly percent: number;
}

export interface MedicationAdherence {
  readonly plan_id: string;
  readonly name: string;
  readonly expected: number;
  readonly taken: number;
  readonly skipped: number;
  readonly missed: number;
  readonly percent: number;
  readonly daily: readonly AdherenceDay[];
}

export interface MedicationAdherenceMeta {
  readonly days: number;
  readonly from: string | null;
  readonly to: string | null;
}

export interface MedicationAdherenceResult {
  readonly rows: readonly MedicationAdherence[];
  readonly meta: MedicationAdherenceMeta;
}

export interface MedicationPlanFilters {
  readonly status?: MedicationPlanStatus | null;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface MedicationRecordFilters {
  readonly planId?: string | null;
  readonly status?: MedicationRecordStatus | null;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface CursorPage<T> {
  readonly items: readonly T[];
  readonly nextCursor: string | null;
  readonly limit: number;
}

export interface MedicationPlanDraft {
  readonly name: string;
  readonly dosage: string;
  readonly form: string;
  readonly route: string;
  readonly dosesPerDay: number;
  readonly scheduleTimes: readonly string[];
  readonly startsOn: string;
  readonly endsOn: string;
  readonly prescribedBy: string;
  readonly instructions: string;
}

export interface MedicationRecordDraft {
  readonly planId: string;
  readonly status: string;
  readonly note: string;
  readonly scheduledFor: string;
}

export interface MedicationPlanWriteBody {
  readonly name: string;
  readonly dosage: string | null;
  readonly form: MedicationForm;
  readonly route: MedicationRoute | null;
  readonly doses_per_day: number;
  readonly schedule_times: readonly string[];
  readonly starts_on: string;
  readonly ends_on: string | null;
  readonly prescribed_by: string | null;
  readonly instructions: string | null;
}

export interface MedicationRecordWriteBody {
  readonly plan_id: string;
  readonly status: MedicationRecordStatus;
  readonly note?: string;
  readonly scheduled_for?: string;
}

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

export function isMedicationForm(value: string): value is MedicationForm {
  return (MEDICATION_FORMS as readonly string[]).includes(value);
}

export function isMedicationRoute(value: string): value is MedicationRoute {
  return (MEDICATION_ROUTES as readonly string[]).includes(value);
}

export function isMedicationPlanStatus(value: string): value is MedicationPlanStatus {
  return (MEDICATION_PLAN_STATUSES as readonly string[]).includes(value);
}

export function isMedicationRecordStatus(value: string): value is MedicationRecordStatus {
  return (MEDICATION_RECORD_STATUSES as readonly string[]).includes(value);
}

export function isScheduleTime(value: string): boolean {
  return TIME_PATTERN.test(value);
}

export function medicationFormLabel(form: string): string {
  switch (form) {
    case 'tablet':
      return 'ยาเม็ด';
    case 'capsule':
      return 'แคปซูล';
    case 'liquid':
      return 'ของเหลว';
    case 'injection':
      return 'ฉีด';
    case 'patch':
      return 'แผ่นแปะ';
    case 'inhaler':
      return 'สูดพ่น';
    default:
      return 'อื่น ๆ';
  }
}

export function medicationRouteLabel(route: string | null): string {
  switch (route) {
    case 'oral':
      return 'รับประทาน';
    case 'topical':
      return 'ทาภายนอก';
    case 'injection':
      return 'ฉีด';
    case 'inhalation':
      return 'สูดดม';
    case 'other':
      return 'อื่น ๆ';
    default:
      return 'ไม่ระบุวิธีใช้';
  }
}

export function medicationPlanStatusLabel(status: string): string {
  switch (status) {
    case 'active':
      return 'ใช้งานอยู่';
    case 'discontinued':
      return 'หยุดใช้แล้ว';
    default:
      return 'ไม่ทราบสถานะ';
  }
}

export function medicationRecordStatusLabel(status: string): string {
  switch (status) {
    case 'taken':
      return 'รับยาแล้ว';
    case 'skipped':
      return 'ข้ามมื้อนี้';
    case 'missed':
      return 'พลาดมื้อนี้';
    default:
      return 'ไม่ทราบสถานะ';
  }
}

export function parseMedicationPlan(value: unknown): MedicationPlan | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const name = readString(raw['name']);

  if (id === null || id === '' || name === null) {
    return null;
  }

  const schedule = readArray(raw['schedule_times'])
    .map((entry) => readString(entry))
    .filter((entry): entry is string => entry !== null);

  return {
    id,
    name,
    dosage: readString(raw['dosage']),
    form: readString(raw['form']) ?? 'other',
    route: readString(raw['route']),
    doses_per_day: readNumber(raw['doses_per_day']) ?? schedule.length,
    schedule_times: schedule,
    starts_on: readString(raw['starts_on']),
    ends_on: readString(raw['ends_on']),
    prescribed_by: readString(raw['prescribed_by']),
    instructions: readString(raw['instructions']),
    status: readString(raw['status']) ?? 'active',
    discontinued_reason: readString(raw['discontinued_reason']),
    discontinued_at: readString(raw['discontinued_at']),
    source: readString(raw['source']) ?? 'manual',
    recorded_by: readString(raw['recorded_by']),
    version: readNumber(raw['version']) ?? 0,
    created_at: readString(raw['created_at']),
    updated_at: readString(raw['updated_at']),
  };
}

export function parseMedicationRecord(value: unknown): MedicationRecord | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const planId = readString(raw['plan_id']);

  if (id === null || id === '' || planId === null || planId === '') {
    return null;
  }

  return {
    id,
    plan_id: planId,
    status: readString(raw['status']) ?? 'taken',
    scheduled_for: readString(raw['scheduled_for']),
    recorded_at: readString(raw['recorded_at']),
    note: readString(raw['note']),
    source: readString(raw['source']) ?? 'manual',
    recorded_by: readString(raw['recorded_by']),
    version: readNumber(raw['version']) ?? 0,
    created_at: readString(raw['created_at']),
    updated_at: readString(raw['updated_at']),
  };
}

function parsePage<T>(value: unknown, parseItem: (item: unknown) => T | null): CursorPage<T> {
  const raw = readObject(value);
  const meta = readObject(raw['meta']);
  const items = readArray(raw['data'])
    .map((item) => parseItem(item))
    .filter((item): item is T => item !== null);

  return {
    items,
    nextCursor: readString(meta['next_cursor']),
    limit: readNumber(meta['limit']) ?? 20,
  };
}

export function parseMedicationPlanPage(value: unknown): CursorPage<MedicationPlan> {
  return parsePage(value, parseMedicationPlan);
}

export function parseMedicationRecordPage(value: unknown): CursorPage<MedicationRecord> {
  return parsePage(value, parseMedicationRecord);
}

function parseAdherenceDay(value: unknown): AdherenceDay | null {
  const raw = readObject(value);
  const date = readString(raw['date']);

  if (date === null || date === '') {
    return null;
  }

  return {
    date,
    expected: readNumber(raw['expected']) ?? 0,
    taken: readNumber(raw['taken']) ?? 0,
    skipped: readNumber(raw['skipped']) ?? 0,
    missed: readNumber(raw['missed']) ?? 0,
    // The server computes `percent`; it is never recalculated in the client.
    percent: readNumber(raw['percent']) ?? 0,
  };
}

function parseAdherenceRow(value: unknown): MedicationAdherence | null {
  const raw = readObject(value);
  const planId = readString(raw['plan_id']);
  const name = readString(raw['name']);

  if (planId === null || planId === '' || name === null) {
    return null;
  }

  return {
    plan_id: planId,
    name,
    expected: readNumber(raw['expected']) ?? 0,
    taken: readNumber(raw['taken']) ?? 0,
    skipped: readNumber(raw['skipped']) ?? 0,
    missed: readNumber(raw['missed']) ?? 0,
    percent: readNumber(raw['percent']) ?? 0,
    daily: readArray(raw['daily'])
      .map((entry) => parseAdherenceDay(entry))
      .filter((entry): entry is AdherenceDay => entry !== null),
  };
}

export function parseMedicationAdherence(value: unknown): MedicationAdherenceResult {
  const raw = readObject(value);
  const meta = readObject(raw['meta']);

  return {
    rows: readArray(raw['data'])
      .map((entry) => parseAdherenceRow(entry))
      .filter((entry): entry is MedicationAdherence => entry !== null),
    meta: {
      days: readNumber(meta['days']) ?? 7,
      from: readString(meta['from']),
      to: readString(meta['to']),
    },
  };
}

/**
 * Active plans first (the API orders by `starts_on`), then discontinued plans
 * so a closed course stays visible without competing with today's therapy.
 */
export function sortMedicationPlans(plans: readonly MedicationPlan[]): readonly MedicationPlan[] {
  const active = plans.filter((plan) => plan.status === 'active');
  const discontinued = plans.filter((plan) => plan.status !== 'active');

  return [...active, ...discontinued];
}

function isValidDate(value: string): boolean {
  return !Number.isNaN(Date.parse(value));
}

export function validateMedicationPlanDraft(
  draft: MedicationPlanDraft,
): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};
  const name = draft.name.trim();
  const dosage = draft.dosage.trim();
  const prescribedBy = draft.prescribedBy.trim();
  const instructions = draft.instructions.trim();
  const times = draft.scheduleTimes.map((time) => time.trim());

  if (name === '') {
    errors['name'] = 'กรุณากรอกชื่อยา';
  } else if (name.length > 255) {
    errors['name'] = 'ชื่อยาต้องไม่เกิน 255 ตัวอักษร';
  }

  if (dosage.length > 100) {
    errors['dosage'] = 'ขนาดยาต้องไม่เกิน 100 ตัวอักษร';
  }

  if (!isMedicationForm(draft.form)) {
    errors['form'] = 'กรุณาเลือกรูปแบบยา';
  }

  if (draft.route !== '' && !isMedicationRoute(draft.route)) {
    errors['route'] = 'กรุณาเลือกวิธีใช้';
  }

  if (!Number.isInteger(draft.dosesPerDay)) {
    errors['doses_per_day'] = 'จำนวนมื้อต่อวันต้องเป็นจํานวนเต็ม';
  } else if (draft.dosesPerDay < DOSES_PER_DAY_MIN || draft.dosesPerDay > DOSES_PER_DAY_MAX) {
    errors['doses_per_day'] =
      `จํานวนมื้อต่อวันต้องอยู่ระหว่าง ${DOSES_PER_DAY_MIN}–${DOSES_PER_DAY_MAX}`;
  } else if (draft.dosesPerDay !== times.length) {
    errors['doses_per_day'] = 'จํานวนมื้อต่อวันต้องเท่ากับจํานวนเวลาที่ตั้งไว้';
  }

  if (times.length < DOSES_PER_DAY_MIN) {
    errors['schedule_times'] = 'ต้องมีเวลารับยาอย่างน้อย 1 เวลา';
  } else if (times.length > DOSES_PER_DAY_MAX) {
    errors['schedule_times'] = `ตั้งเวลาได้ไม่เกิน ${DOSES_PER_DAY_MAX} เวลา`;
  } else if (times.some((time) => !isScheduleTime(time))) {
    errors['schedule_times'] = 'เวลาแต่ละมื้อต้องอยู่ในรูปแบบ HH:MM';
  } else if (new Set(times).size !== times.length) {
    errors['schedule_times'] = 'เวลารับยาต้องไม่ซ้ํากัน';
  }

  if (draft.startsOn === '') {
    errors['starts_on'] = 'กรุณาระบุวันเริ่มใช้ยา';
  } else if (!isValidDate(draft.startsOn)) {
    errors['starts_on'] = 'รูปแบบวันที่ไม่ถูกต้อง';
  }

  if (draft.endsOn !== '') {
    if (!isValidDate(draft.endsOn)) {
      errors['ends_on'] = 'รูปแบบวันที่ไม่ถูกต้อง';
    } else if (draft.startsOn !== '' && draft.endsOn < draft.startsOn) {
      errors['ends_on'] = 'วันสิ้นสุดต้องไม่ก่อนวันเริ่มต้น';
    }
  }

  if (prescribedBy.length > 255) {
    errors['prescribed_by'] = 'ชื่อผู้สั่งยาต้องไม่เกิน 255 ตัวอักษร';
  }

  if (instructions.length > 5000) {
    errors['instructions'] = 'คําแนะนําต้องไม่เกิน 5,000 ตัวอักษร';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function buildMedicationPlanBody(draft: MedicationPlanDraft): MedicationPlanWriteBody {
  const times = draft.scheduleTimes.map((time) => time.trim());
  const dosage = draft.dosage.trim();
  const prescribedBy = draft.prescribedBy.trim();
  const instructions = draft.instructions.trim();

  return {
    name: draft.name.trim(),
    dosage: dosage === '' ? null : dosage,
    form: isMedicationForm(draft.form) ? draft.form : 'other',
    route: isMedicationRoute(draft.route) ? draft.route : null,
    // The count is the array length by construction, so the two can never
    // disagree (the backend rejects a mismatch with 422 as a backstop).
    doses_per_day: times.length,
    schedule_times: times,
    starts_on: draft.startsOn,
    ends_on: draft.endsOn === '' ? null : draft.endsOn,
    prescribed_by: prescribedBy === '' ? null : prescribedBy,
    instructions: instructions === '' ? null : instructions,
  };
}

export function validateMedicationRecordDraft(
  draft: MedicationRecordDraft,
): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};
  const note = draft.note.trim();

  if (draft.planId === '') {
    errors['plan_id'] = 'กรุณาเลือกแผนยา';
  }

  if (!isMedicationRecordStatus(draft.status)) {
    errors['status'] = 'กรุณาเลือกสถานะการรับยา';
  }

  if (draft.status === 'skipped' && note === '') {
    errors['note'] = 'กรุณาระบุเหตุผลที่ข้ามมื้อนี้';
  } else if (note.length > 2000) {
    errors['note'] = 'บันทึกต้องไม่เกิน 2,000 ตัวอักษร';
  }

  if (draft.scheduledFor !== '' && Number.isNaN(Date.parse(draft.scheduledFor))) {
    errors['scheduled_for'] = 'รูปแบบวันที่และเวลาไม่ถูกต้อง';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function buildMedicationRecordBody(draft: MedicationRecordDraft): MedicationRecordWriteBody {
  const note = draft.note.trim();
  const body: {
    plan_id: string;
    status: MedicationRecordStatus;
    note?: string;
    scheduled_for?: string;
  } = {
    plan_id: draft.planId,
    status: isMedicationRecordStatus(draft.status) ? draft.status : 'taken',
  };

  if (note !== '') {
    body.note = note;
  }

  if (draft.scheduledFor !== '') {
    body.scheduled_for = draft.scheduledFor;
  }

  return body;
}

/**
 * `scheduled_for` from a `datetime-local` control is a wall-clock value; send
 * the same local ISO string the user picked so the server can map it to the
 * plan's timezone instead of shifting it through the browser's UTC offset.
 */
export function normalizeScheduledFor(value: string): string {
  const trimmed = value.trim();

  if (trimmed === '') {
    return '';
  }

  return trimmed.length === 16 ? `${trimmed}:00` : trimmed;
}
