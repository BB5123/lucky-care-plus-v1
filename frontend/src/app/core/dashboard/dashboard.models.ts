/**
 * Typed mirror of `DashboardAssembler` (backend) — one interface per zone.
 *
 * The backend is the authority on visibility and values. This module only
 * narrows an `unknown` JSON body into the exact shape the API documents, and
 * treats anything malformed or absent as hidden rather than throwing.
 */

export type FamilyRing = 'inner' | 'support' | 'professional';
export type HealthStatus = 'green' | 'yellow' | 'red';

export interface FamilyMember {
  readonly person_id: string;
  /**
   * The health-profile id. `person_id` keys the list, but every health endpoint
   * and the dashboard `?profile_id=` filter want this one — without it the
   * family switcher cannot drill down.
   */
  readonly profile_id: string;
  readonly display_name: string;
  readonly ring: string;
  readonly status: string;
  readonly reason: string;
}

export interface FamilyZone {
  readonly visible: boolean;
  readonly reason?: string;
  readonly members: readonly FamilyMember[];
}

export interface AlertItem {
  readonly level: string;
  readonly person_id: string | null;
  readonly code: string;
  readonly task_id: string | null;
  readonly message_key: string;
}

export interface AlertsZone {
  readonly visible: boolean;
  readonly reason?: string;
  readonly items: readonly AlertItem[];
}

export interface TaskCard {
  readonly id: string;
  readonly type: string;
  readonly title: string;
  readonly due_at: string | null;
  readonly status: string;
  readonly priority: string;
  readonly profile_display_name: string | null;
  readonly version: number;
}

export interface TasksTodayZone {
  readonly visible: boolean;
  readonly reason?: string;
  readonly total: number;
  readonly done: number;
  readonly overdue: number;
  readonly items: readonly TaskCard[];
}

export interface VitalLatest {
  readonly value: number | null;
  readonly secondary_value: number | null;
  readonly unit: string;
  readonly recorded_at: string | null;
}

export interface VitalTrend {
  readonly count: number;
  readonly min: number | null;
  readonly max: number | null;
  readonly avg: number | null;
}

export interface VitalCard {
  readonly type: string;
  readonly person_id: string;
  readonly status: string;
  readonly latest: VitalLatest;
  readonly trend_7d: VitalTrend;
}

export interface VitalsZone {
  readonly visible: boolean;
  readonly reason?: string;
  readonly cards: readonly VitalCard[];
}

export interface NextAppointmentZone {
  readonly visible: boolean;
  readonly reason?: string;
  readonly id: string | null;
  /** The appointment belongs to a health profile, so this is a profile id. */
  readonly profile_id: string | null;
  readonly title: string | null;
  readonly scheduled_at: string | null;
  readonly status: string | null;
}

export interface DashboardPayload {
  readonly family: FamilyZone;
  readonly alerts: AlertsZone;
  readonly tasks_today: TasksTodayZone;
  readonly vitals: VitalsZone;
  readonly next_appointment: NextAppointmentZone;
  readonly generated_at: string | null;
  readonly cache_ttl: number;
}

export const HIDDEN_REASON_ABSENT = 'absent';
export const HIDDEN_REASON_OUT_OF_SCOPE = 'out_of_scope';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readObject(value: unknown): Record<string, unknown> {
  return isRecord(value) ? value : {};
}

function readString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function readBoolean(value: unknown): boolean {
  return value === true;
}

function readArray(value: unknown): readonly unknown[] {
  return Array.isArray(value) ? value : [];
}

function hiddenZone<T>(base: T, reason: string): T {
  return { ...base, visible: false, reason };
}

function readMembers(value: unknown): readonly FamilyMember[] {
  return readArray(value)
    .map((entry): FamilyMember | null => {
      const item = readObject(entry);
      const personId = readString(item['person_id']);

      if (personId === null || personId === '') {
        return null;
      }

      return {
        person_id: personId,
        profile_id: readString(item['profile_id']) ?? '',
        display_name: readString(item['display_name']) ?? '',
        ring: readString(item['ring']) ?? 'support',
        status: readString(item['status']) ?? 'green',
        reason: readString(item['reason']) ?? 'stable',
      };
    })
    .filter((entry): entry is FamilyMember => entry !== null);
}

function parseFamily(value: unknown): FamilyZone {
  const empty: FamilyZone = {
    visible: false,
    reason: HIDDEN_REASON_ABSENT,
    members: [],
  };

  if (value === undefined || value === null) {
    return empty;
  }

  const raw = readObject(value);

  if (!readBoolean(raw['visible'])) {
    return hiddenZone(empty, readString(raw['reason']) ?? HIDDEN_REASON_OUT_OF_SCOPE);
  }

  return {
    visible: true,
    members: readMembers(raw['members']),
  };
}

function readAlerts(value: unknown): readonly AlertItem[] {
  return readArray(value)
    .map((entry): AlertItem | null => {
      const item = readObject(entry);
      const messageKey = readString(item['message_key']);

      if (messageKey === null || messageKey === '') {
        return null;
      }

      return {
        level: readString(item['level']) ?? 'info',
        person_id: readString(item['person_id']),
        code: readString(item['code']) ?? '',
        task_id: readString(item['task_id']),
        message_key: messageKey,
      };
    })
    .filter((entry): entry is AlertItem => entry !== null);
}

function parseAlerts(value: unknown): AlertsZone {
  const empty: AlertsZone = {
    visible: false,
    reason: HIDDEN_REASON_ABSENT,
    items: [],
  };

  if (value === undefined || value === null) {
    return empty;
  }

  const raw = readObject(value);

  if (!readBoolean(raw['visible'])) {
    return hiddenZone(empty, readString(raw['reason']) ?? HIDDEN_REASON_OUT_OF_SCOPE);
  }

  return {
    visible: true,
    items: readAlerts(raw['items']),
  };
}

function readTasks(value: unknown): readonly TaskCard[] {
  return readArray(value)
    .map((entry): TaskCard | null => {
      const item = readObject(entry);
      const id = readString(item['id']);
      const title = readString(item['title']);

      if (id === null || id === '' || title === null) {
        return null;
      }

      return {
        id,
        type: readString(item['type']) ?? '',
        title,
        due_at: readString(item['due_at']),
        status: readString(item['status']) ?? 'open',
        priority: readString(item['priority']) ?? 'normal',
        profile_display_name: readString(item['profile_display_name']),
        version: readNumber(item['version']) ?? 0,
      };
    })
    .filter((entry): entry is TaskCard => entry !== null);
}

function parseTasksToday(value: unknown): TasksTodayZone {
  const empty: TasksTodayZone = {
    visible: false,
    reason: HIDDEN_REASON_ABSENT,
    total: 0,
    done: 0,
    overdue: 0,
    items: [],
  };

  if (value === undefined || value === null) {
    return empty;
  }

  const raw = readObject(value);

  if (!readBoolean(raw['visible'])) {
    return hiddenZone(empty, readString(raw['reason']) ?? HIDDEN_REASON_OUT_OF_SCOPE);
  }

  return {
    visible: true,
    total: readNumber(raw['total']) ?? 0,
    done: readNumber(raw['done']) ?? 0,
    overdue: readNumber(raw['overdue']) ?? 0,
    items: readTasks(raw['items']),
  };
}

function readVitals(value: unknown): readonly VitalCard[] {
  return readArray(value)
    .map((entry): VitalCard | null => {
      const item = readObject(entry);
      const type = readString(item['type']);

      if (type === null || type === '') {
        return null;
      }

      const latest = readObject(item['latest']);
      const trend = readObject(item['trend_7d']);

      return {
        type,
        person_id: readString(item['person_id']) ?? '',
        status: readString(item['status']) ?? 'normal',
        latest: {
          value: readNumber(latest['value']),
          secondary_value: readNumber(latest['secondary_value']),
          unit: readString(latest['unit']) ?? '',
          recorded_at: readString(latest['recorded_at']),
        },
        trend_7d: {
          count: readNumber(trend['count']) ?? 0,
          min: readNumber(trend['min']),
          max: readNumber(trend['max']),
          avg: readNumber(trend['avg']),
        },
      };
    })
    .filter((entry): entry is VitalCard => entry !== null);
}

function parseVitals(value: unknown): VitalsZone {
  const empty: VitalsZone = {
    visible: false,
    reason: HIDDEN_REASON_ABSENT,
    cards: [],
  };

  if (value === undefined || value === null) {
    return empty;
  }

  const raw = readObject(value);

  if (!readBoolean(raw['visible'])) {
    return hiddenZone(empty, readString(raw['reason']) ?? HIDDEN_REASON_OUT_OF_SCOPE);
  }

  return {
    visible: true,
    cards: readVitals(raw['cards']),
  };
}

function parseNextAppointment(value: unknown): NextAppointmentZone {
  const empty: NextAppointmentZone = {
    visible: false,
    reason: HIDDEN_REASON_ABSENT,
    id: null,
    profile_id: null,
    title: null,
    scheduled_at: null,
    status: null,
  };

  if (value === undefined || value === null) {
    return empty;
  }

  const raw = readObject(value);

  if (!readBoolean(raw['visible'])) {
    return hiddenZone(empty, readString(raw['reason']) ?? HIDDEN_REASON_OUT_OF_SCOPE);
  }

  return {
    visible: true,
    id: readString(raw['id']),
    profile_id: readString(raw['profile_id']),
    title: readString(raw['title']),
    scheduled_at: readString(raw['scheduled_at']),
    status: readString(raw['status']),
  };
}

/**
 * Narrows an untrusted payload. Never throws: a missing or malformed zone is
 * simply hidden, which is the fail-closed behaviour required by F-01/F-09.
 */
export function parseDashboardPayload(value: unknown): DashboardPayload {
  const raw = readObject(value);

  return {
    family: parseFamily(raw['family']),
    alerts: parseAlerts(raw['alerts']),
    tasks_today: parseTasksToday(raw['tasks_today']),
    vitals: parseVitals(raw['vitals']),
    next_appointment: parseNextAppointment(raw['next_appointment']),
    generated_at: readString(raw['generated_at']),
    cache_ttl: readNumber(raw['cache_ttl']) ?? 0,
  };
}
