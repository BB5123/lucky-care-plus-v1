/**
 * Typed mirror of `GET /workspaces/{w}/profiles/{p}/timeline`.
 *
 * The backend owns redaction: a redacted entry exposes only `type` and
 * `occurred_at` (CRM-014). This parser enforces that promise locally too — even
 * if a redacted row accidentally carried an id or metadata, those values are
 * dropped before they can reach the DOM (F-06 / N-02).
 */

export const TIMELINE_TYPES = [
  'measurement',
  'condition',
  'allergy',
  'medication_plan',
  'medication_record',
  'care_task',
  'appointment',
] as const;

export type TimelineType = (typeof TIMELINE_TYPES)[number];

export interface TimelineEntryRef {
  readonly type: string;
  readonly id: string;
}

export interface TimelineEntry {
  /** `null` for a redacted entry: the client never invents an id. */
  readonly id: string | null;
  readonly type: string;
  readonly occurred_at: string | null;
  readonly summary_key: string | null;
  readonly ref: TimelineEntryRef | null;
  readonly metadata: Readonly<Record<string, unknown>>;
  readonly redacted: boolean;
}

export interface TimelineFilters {
  readonly from?: string | null;
  readonly to?: string | null;
  readonly types?: readonly string[] | null;
  readonly cursor?: string | null;
  readonly limit?: number;
}

export interface TimelinePage {
  readonly items: readonly TimelineEntry[];
  readonly nextCursor: string | null;
  readonly limit: number;
}

const EMPTY_METADATA: Readonly<Record<string, unknown>> = {};

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

export function isTimelineType(value: string): value is TimelineType {
  return (TIMELINE_TYPES as readonly string[]).includes(value);
}

/**
 * Narrows one entry. A redacted row is always accepted when it has a type,
 * because "something happened" is the only fact the API is allowed to share.
 */
export function parseTimelineEntry(value: unknown): TimelineEntry | null {
  const raw = readObject(value);
  const type = readString(raw['type']);

  if (type === null || type === '') {
    return null;
  }

  const occurredAt = readString(raw['occurred_at']);
  const redacted = raw['redacted'] === true;

  if (redacted) {
    return {
      id: null,
      type,
      occurred_at: occurredAt,
      summary_key: null,
      ref: null,
      metadata: EMPTY_METADATA,
      redacted: true,
    };
  }

  const id = readString(raw['id']);
  const summaryKey = readString(raw['summary_key']);

  if (id === null || id === '' || summaryKey === null || summaryKey === '') {
    return null;
  }

  const rawRef = readObject(raw['ref']);
  const refType = readString(rawRef['type']);
  const refId = readString(rawRef['id']);

  return {
    id,
    type,
    occurred_at: occurredAt,
    summary_key: summaryKey,
    ref: refType !== null && refId !== null ? { type: refType, id: refId } : null,
    metadata: { ...readObject(raw['metadata']) },
    redacted: false,
  };
}

function parsePage(value: unknown): TimelinePage {
  const raw = readObject(value);
  const meta = readObject(raw['meta']);
  const items = readArray(raw['data'])
    .map((item) => parseTimelineEntry(item))
    .filter((item): item is TimelineEntry => item !== null);

  return {
    items,
    nextCursor: readString(meta['next_cursor']),
    limit: readNumber(meta['limit']) ?? 20,
  };
}

export function parseTimelinePage(value: unknown): TimelinePage {
  return parsePage(value);
}

/**
 * The API cursor is stable, but a defensive merge still removes an entry that
 * appears on two pages. Redacted rows have no id, so their type + timestamp is
 * the only available identity.
 */
function entryKey(entry: TimelineEntry): string {
  return entry.id !== null ? `id:${entry.id}` : `redacted:${entry.type}:${entry.occurred_at ?? ''}`;
}

export function mergeTimelineEntries(
  current: readonly TimelineEntry[],
  incoming: readonly TimelineEntry[],
): readonly TimelineEntry[] {
  const seen = new Set(current.map((entry) => entryKey(entry)));
  const added = incoming.filter((entry) => {
    const key = entryKey(entry);

    if (seen.has(key)) {
      return false;
    }

    seen.add(key);

    return true;
  });

  return [...current, ...added];
}
