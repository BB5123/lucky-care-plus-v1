/**
 * Doctor Brief contract (Phase 2.3 / web UI).
 *
 * The server freezes `payload` at creation time; the UI renders a short
 * preview and the share link. Fields are read defensively — the payload is
 * untrusted JSON and may omit sections the actor could not read.
 */
export interface HealthReportProfile {
  readonly name: string | null;
  readonly birthYear: number | null;
}

export interface HealthReportPayload {
  readonly sections: readonly string[];
  readonly profile: HealthReportProfile | null;
  readonly conditions: ReadonlyArray<{ readonly name: string; readonly diagnosedYear: number | null }>;
  readonly allergies: ReadonlyArray<{ readonly substance: string; readonly severity: string }>;
  readonly medications: ReadonlyArray<{ readonly name: string; readonly schedule: readonly string[] }>;
  readonly vitals: ReadonlyArray<{ readonly type: string; readonly count: number }>;
  readonly appointments: ReadonlyArray<{ readonly status: string }>;
  readonly careTasksOpen: number | null;
}

export interface HealthReport {
  readonly id: string;
  readonly kind: string;
  readonly periodFrom: string | null;
  readonly periodTo: string | null;
  readonly createdAt: string | null;
  readonly payload: HealthReportPayload | null;
}

export interface ReportShare {
  readonly id: string;
  readonly url: string;
  readonly expiresAt: string | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function readString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function readArray(value: unknown): readonly Record<string, unknown>[] {
  return Array.isArray(value) ? value.filter(isRecord) : [];
}

function parsePayload(value: unknown): HealthReportPayload | null {
  if (!isRecord(value)) {
    return null;
  }

  const profile = isRecord(value['profile'])
    ? {
        name: readString(value['profile']['name']),
        birthYear: readNumber(value['profile']['birth_year']),
      }
    : null;

  const sections = Array.isArray(value['sections'])
    ? value['sections'].filter((item): item is string => typeof item === 'string')
    : [];

  return {
    sections,
    profile,
    conditions: readArray(value['conditions']).map((item) => ({
      name: readString(item['name']) ?? 'ไม่ระบุ',
      diagnosedYear: readNumber(item['diagnosed_year']),
    })),
    allergies: readArray(value['allergies']).map((item) => ({
      substance: readString(item['substance']) ?? 'ไม่ระบุ',
      severity: readString(item['severity']) ?? '',
    })),
    medications: readArray(value['medications']).map((item) => ({
      name: readString(item['name']) ?? 'ไม่ระบุ',
      schedule: Array.isArray(item['schedule'])
        ? item['schedule'].filter((entry): entry is string => typeof entry === 'string')
        : [],
    })),
    vitals: readArray(value['vitals']).map((item) => ({
      type: readString(item['type']) ?? 'ไม่ระบุ',
      count: readNumber(item['count']) ?? 0,
    })),
    appointments: readArray(value['appointments']).map((item) => ({
      status: readString(item['status']) ?? '',
    })),
    careTasksOpen: isRecord(value['care_tasks']) ? readNumber(value['care_tasks']['total_open']) : null,
  };
}

export function parseHealthReport(value: unknown): HealthReport | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);

  if (id === null) {
    return null;
  }

  return {
    id,
    kind: readString(value['kind']) ?? 'doctor_brief',
    periodFrom: readString(value['period_from']),
    periodTo: readString(value['period_to']),
    createdAt: readString(value['created_at']),
    payload: parsePayload(value['payload']),
  };
}

export function parseReportShare(value: unknown): ReportShare | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);
  const url = readString(value['url']);

  if (id === null || url === null) {
    return null;
  }

  return {
    id,
    url,
    expiresAt: readString(value['expires_at']),
  };
}
