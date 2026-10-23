/**
 * Typed mirror of the Health Vault API (`/profiles/{profile}/measurements`,
 * `conditions`, `allergies`).
 *
 * The backend stays the authority on ranges, units and permissions. This module
 * narrows untrusted JSON into the documented shapes, and exposes the same range
 * metadata the server uses (`MeasurementType` in the Laravel module) so the
 * form can give instant feedback without inventing a second rule set.
 */

export const MEASUREMENT_TYPES = [
  'blood_pressure',
  'blood_glucose',
  'heart_rate',
  'weight',
  'spo2',
  'temperature',
] as const;

export type MeasurementType = (typeof MEASUREMENT_TYPES)[number];

export const BLOOD_GLUCOSE_CONTEXTS = ['fasting', 'postprandial', 'random'] as const;

export type BloodGlucoseContext = (typeof BLOOD_GLUCOSE_CONTEXTS)[number];

export const ALLERGY_SEVERITIES = ['mild', 'moderate', 'severe'] as const;

export type AllergySeverity = (typeof ALLERGY_SEVERITIES)[number];

export const CONDITION_STATUSES = ['active', 'resolved'] as const;

export type ConditionStatus = (typeof CONDITION_STATUSES)[number];

export interface MeasurementTypeInfo {
  readonly label: string;
  readonly unit: string;
  readonly minimum: number;
  readonly maximum: number;
  readonly secondaryLabel: string | null;
  readonly secondaryMinimum: number | null;
  readonly secondaryMaximum: number | null;
  readonly contextRequired: boolean;
}

export const MEASUREMENT_TYPE_INFO: Readonly<Record<MeasurementType, MeasurementTypeInfo>> = {
  blood_pressure: {
    label: 'ความดันโลหิต',
    unit: 'mmHg',
    minimum: 50,
    maximum: 300,
    secondaryLabel: 'ค่าล่าง (diastolic)',
    secondaryMinimum: 30,
    secondaryMaximum: 200,
    contextRequired: false,
  },
  blood_glucose: {
    label: 'น้ําตาลในเลือด',
    unit: 'mg/dL',
    minimum: 20,
    maximum: 800,
    secondaryLabel: null,
    secondaryMinimum: null,
    secondaryMaximum: null,
    contextRequired: true,
  },
  heart_rate: {
    label: 'ชีพจร',
    unit: 'bpm',
    minimum: 20,
    maximum: 250,
    secondaryLabel: null,
    secondaryMinimum: null,
    secondaryMaximum: null,
    contextRequired: false,
  },
  weight: {
    label: 'น้ําหนัก',
    unit: 'kg',
    minimum: 1,
    maximum: 400,
    secondaryLabel: null,
    secondaryMinimum: null,
    secondaryMaximum: null,
    contextRequired: false,
  },
  spo2: {
    label: 'ออกซิเจนในเลือด',
    unit: '%',
    minimum: 50,
    maximum: 100,
    secondaryLabel: null,
    secondaryMinimum: null,
    secondaryMaximum: null,
    contextRequired: false,
  },
  temperature: {
    label: 'อุณหภูมิ',
    unit: '°C',
    minimum: 30,
    maximum: 45,
    secondaryLabel: null,
    secondaryMinimum: null,
    secondaryMaximum: null,
    contextRequired: false,
  },
};

export interface Measurement {
  readonly id: string;
  readonly type: MeasurementType;
  readonly value: number;
  readonly secondary_value: number | null;
  readonly unit: string;
  readonly context: string | null;
  readonly note: string | null;
  readonly source: string;
  readonly recorded_at: string | null;
  readonly recorded_by: string | null;
  readonly version: number;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface Condition {
  readonly id: string;
  readonly code: string | null;
  readonly name: string;
  readonly diagnosed_at: string | null;
  readonly status: string;
  readonly notes: string | null;
  readonly version: number;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface Allergy {
  readonly id: string;
  readonly substance: string;
  readonly reaction: string | null;
  readonly severity: string;
  readonly noted_at: string | null;
  readonly version: number;
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

export interface TrendPoint {
  readonly date: string;
  readonly min: number;
  readonly max: number;
  readonly avg: number;
  readonly count: number;
}

export interface MeasurementFilters {
  readonly type?: MeasurementType | null;
  readonly from?: string | null;
  readonly to?: string | null;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface ConditionFilters {
  readonly status?: ConditionStatus | null;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface AllergyFilters {
  readonly severity?: AllergySeverity | null;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface MeasurementCreateBody {
  readonly type: MeasurementType;
  readonly value: number;
  readonly secondary_value?: number;
  readonly context?: BloodGlucoseContext;
  readonly note?: string;
}

export interface ConditionWriteBody {
  readonly code: string | null;
  readonly name: string;
  readonly diagnosed_at: string | null;
  readonly status: ConditionStatus;
  readonly notes: string | null;
}

export interface AllergyWriteBody {
  readonly substance: string;
  readonly reaction: string | null;
  readonly severity: AllergySeverity;
  readonly noted_at: string | null;
}

export interface CursorPage<T> {
  readonly items: readonly T[];
  readonly nextCursor: string | null;
  readonly limit: number;
}

export interface MeasurementDraft {
  readonly type: MeasurementType;
  readonly value: string | number | null;
  readonly secondary_value: string | number | null;
  readonly context: string;
  readonly note: string;
}

export interface ConditionDraft {
  readonly code: string;
  readonly name: string;
  readonly diagnosed_at: string;
  readonly status: string;
  readonly notes: string;
}

export interface AllergyDraft {
  readonly substance: string;
  readonly reaction: string;
  readonly severity: string;
  readonly noted_at: string;
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

function readNullableNumber(value: unknown): number | null {
  return readNumber(value);
}

export function isMeasurementType(value: string): value is MeasurementType {
  return (MEASUREMENT_TYPES as readonly string[]).includes(value);
}

export function isBloodGlucoseContext(value: string): value is BloodGlucoseContext {
  return (BLOOD_GLUCOSE_CONTEXTS as readonly string[]).includes(value);
}

export function isConditionStatus(value: string): value is ConditionStatus {
  return (CONDITION_STATUSES as readonly string[]).includes(value);
}

export function isAllergySeverity(value: string): value is AllergySeverity {
  return (ALLERGY_SEVERITIES as readonly string[]).includes(value);
}

export function measurementTypeInfo(type: string): MeasurementTypeInfo | null {
  return isMeasurementType(type) ? MEASUREMENT_TYPE_INFO[type] : null;
}

export function measurementTypeLabel(type: string): string {
  return measurementTypeInfo(type)?.label ?? 'ค่าสุขภาพ';
}

export function measurementUnit(type: string): string {
  return measurementTypeInfo(type)?.unit ?? '';
}

export function measurementContextLabel(context: string | null): string {
  switch (context) {
    case 'fasting':
      return 'หลังอดอาหาร';
    case 'postprandial':
      return 'หลังอาหาร';
    case 'random':
      return 'สุ่มวัด';
    case 'morning':
      return 'ช่วงเช้า';
    case 'evening':
      return 'ช่วงเย็น';
    default:
      return '';
  }
}

export function conditionStatusLabel(status: string): string {
  switch (status) {
    case 'active':
      return 'กําลังรักษา';
    case 'resolved':
      return 'หายแล้ว';
    default:
      return 'ไม่ทราบสถานะ';
  }
}

export function allergySeverityLabel(severity: string): string {
  switch (severity) {
    case 'mild':
      return 'เล็กน้อย';
    case 'moderate':
      return 'ปานกลาง';
    case 'severe':
      return 'รุนแรง';
    default:
      return 'ไม่ทราบระดับ';
  }
}

export function parseMeasurement(value: unknown): Measurement | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const type = readString(raw['type']);
  const numericValue = readNumber(raw['value']);

  if (
    id === null ||
    id === '' ||
    type === null ||
    !isMeasurementType(type) ||
    numericValue === null
  ) {
    return null;
  }

  return {
    id,
    type,
    value: numericValue,
    secondary_value: readNullableNumber(raw['secondary_value']),
    unit: readString(raw['unit']) ?? MEASUREMENT_TYPE_INFO[type].unit,
    context: readString(raw['context']),
    note: readString(raw['note']),
    source: readString(raw['source']) ?? 'manual',
    recorded_at: readString(raw['recorded_at']),
    recorded_by: readString(raw['recorded_by']),
    version: readNumber(raw['version']) ?? 0,
    created_at: readString(raw['created_at']),
    updated_at: readString(raw['updated_at']),
  };
}

export function parseCondition(value: unknown): Condition | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const name = readString(raw['name']);

  if (id === null || id === '' || name === null) {
    return null;
  }

  return {
    id,
    code: readString(raw['code']),
    name,
    diagnosed_at: readString(raw['diagnosed_at']),
    status: readString(raw['status']) ?? 'active',
    notes: readString(raw['notes']),
    version: readNumber(raw['version']) ?? 0,
    created_at: readString(raw['created_at']),
    updated_at: readString(raw['updated_at']),
  };
}

export function parseAllergy(value: unknown): Allergy | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const substance = readString(raw['substance']);

  if (id === null || id === '' || substance === null) {
    return null;
  }

  return {
    id,
    substance,
    reaction: readString(raw['reaction']),
    severity: readString(raw['severity']) ?? 'mild',
    noted_at: readString(raw['noted_at']),
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

export function parseMeasurementPage(value: unknown): CursorPage<Measurement> {
  return parsePage(value, parseMeasurement);
}

export function parseConditionPage(value: unknown): CursorPage<Condition> {
  return parsePage(value, parseCondition);
}

export function parseAllergyPage(value: unknown): CursorPage<Allergy> {
  return parsePage(value, parseAllergy);
}

export function parseTrendPoints(value: unknown): readonly TrendPoint[] {
  return readArray(value)
    .map((entry): TrendPoint | null => {
      const raw = readObject(entry);
      const date = readString(raw['date']);
      const min = readNumber(raw['min']);
      const max = readNumber(raw['max']);
      const avg = readNumber(raw['avg']);
      const count = readNumber(raw['count']);

      if (date === null || min === null || max === null || avg === null || count === null) {
        return null;
      }

      return { date, min, max, avg, count };
    })
    .filter((entry): entry is TrendPoint => entry !== null);
}

function readDraftNumber(value: string | number | null): number | null {
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }

  if (value === null) {
    return null;
  }

  const trimmed = value.trim();

  if (trimmed === '') {
    return null;
  }

  const parsed = Number(trimmed);

  return Number.isFinite(parsed) ? parsed : null;
}

function formatRange(minimum: number, maximum: number): string {
  return `${minimum}–${maximum}`;
}

export function validateMeasurementDraft(
  draft: MeasurementDraft,
): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};
  const info = MEASUREMENT_TYPE_INFO[draft.type];
  const value = readDraftNumber(draft.value);

  if (value === null) {
    errors['value'] = 'กรุณากรอกค่าเป็นตัวเลข';
  } else if (value < info.minimum || value > info.maximum) {
    errors['value'] = `ค่าที่รับได้คือ ${formatRange(info.minimum, info.maximum)} ${info.unit}`;
  }

  if (draft.type === 'blood_pressure') {
    const secondary = readDraftNumber(draft.secondary_value);

    if (secondary === null) {
      errors['secondary_value'] = 'กรุณากรอกค่าล่าง';
    } else if (secondary < 30 || secondary > 200) {
      errors['secondary_value'] = 'ค่าที่รับได้คือ 30–200 mmHg';
    } else if (value !== null && value <= secondary) {
      errors['value'] = 'ค่าบนต้องมากกว่าค่าล่าง';
    }
  }

  if (draft.type === 'blood_glucose' && !isBloodGlucoseContext(draft.context)) {
    errors['context'] = 'กรุณาเลือกช่วงเวลาที่วัด';
  }

  if (draft.note.trim().length > 500) {
    errors['note'] = 'บันทึกต้องไม่เกิน 500 ตัวอักษร';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function buildMeasurementBody(draft: MeasurementDraft): MeasurementCreateBody {
  const body: {
    type: MeasurementType;
    value: number;
    secondary_value?: number;
    context?: BloodGlucoseContext;
    note?: string;
  } = {
    type: draft.type,
    value: Number(draft.value),
  };

  if (draft.type === 'blood_pressure') {
    body.secondary_value = Number(draft.secondary_value);
  }

  if (draft.type === 'blood_glucose' && isBloodGlucoseContext(draft.context)) {
    body.context = draft.context;
  }

  const note = draft.note.trim();

  if (note !== '') {
    body.note = note;
  }

  return body;
}

export function validateConditionDraft(draft: ConditionDraft): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};
  const name = draft.name.trim();
  const code = draft.code.trim();
  const notes = draft.notes.trim();

  if (name === '') {
    errors['name'] = 'กรุณากรอกชื่อโรค';
  } else if (name.length > 255) {
    errors['name'] = 'ชื่อโรคต้องไม่เกิน 255 ตัวอักษร';
  }

  if (code.length > 16) {
    errors['code'] = 'รหัสโรคต้องไม่เกิน 16 ตัวอักษร';
  }

  if (notes.length > 5000) {
    errors['notes'] = 'บันทึกต้องไม่เกิน 5,000 ตัวอักษร';
  }

  if (!isConditionStatus(draft.status)) {
    errors['status'] = 'กรุณาเลือกสถานะ';
  }

  if (draft.diagnosed_at !== '' && Number.isNaN(Date.parse(draft.diagnosed_at))) {
    errors['diagnosed_at'] = 'รูปแบบวันที่ไม่ถูกต้อง';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function buildConditionBody(draft: ConditionDraft): ConditionWriteBody {
  const code = draft.code.trim();
  const notes = draft.notes.trim();

  return {
    code: code === '' ? null : code,
    name: draft.name.trim(),
    diagnosed_at: draft.diagnosed_at === '' ? null : draft.diagnosed_at,
    status: isConditionStatus(draft.status) ? draft.status : 'active',
    notes: notes === '' ? null : notes,
  };
}

export function validateAllergyDraft(draft: AllergyDraft): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};
  const substance = draft.substance.trim();
  const reaction = draft.reaction.trim();

  if (substance === '') {
    errors['substance'] = 'กรุณากรอกชื่อสารที่แพ้';
  } else if (substance.length > 255) {
    errors['substance'] = 'ชื่อสารต้องไม่เกิน 255 ตัวอักษร';
  }

  if (reaction.length > 500) {
    errors['reaction'] = 'อาการต้องไม่เกิน 500 ตัวอักษร';
  }

  if (!isAllergySeverity(draft.severity)) {
    errors['severity'] = 'กรุณาเลือกระดับความรุนแรง';
  }

  if (draft.noted_at !== '' && Number.isNaN(Date.parse(draft.noted_at))) {
    errors['noted_at'] = 'รูปแบบวันที่ไม่ถูกต้อง';
  }

  return Object.keys(errors).length === 0 ? EMPTY_FIELD_ERRORS : errors;
}

export function buildAllergyBody(draft: AllergyDraft): AllergyWriteBody {
  const reaction = draft.reaction.trim();

  return {
    substance: draft.substance.trim(),
    reaction: reaction === '' ? null : reaction,
    severity: isAllergySeverity(draft.severity) ? draft.severity : 'mild',
    noted_at: draft.noted_at === '' ? null : draft.noted_at,
  };
}
