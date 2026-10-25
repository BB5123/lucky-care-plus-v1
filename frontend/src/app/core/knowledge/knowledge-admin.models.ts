/**
 * Platform knowledge-corpus administration (CR-005 / baseline v1.4.0).
 *
 * The admin list endpoint returns public corpus metadata only: title,
 * publisher, fetch date, review state and staleness. The source body is never
 * part of the payload, and this parser deliberately keeps no unknown field so
 * a future server addition cannot leak text into the review screen.
 */
export type KnowledgeSourceStatus = 'reviewed' | 'unreviewed';

export interface AdminKnowledgeSource {
  readonly id: string;
  readonly title: string;
  readonly publisher: string;
  readonly fetchedAt: string;
  readonly reviewStatus: string;
  readonly reviewedBy: string | null;
  readonly reviewedAt: string | null;
  readonly stale: boolean;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function readOptionalString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function parseSource(value: unknown): AdminKnowledgeSource | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);
  const title = readString(value['title']);
  const publisher = readString(value['publisher']);
  const fetchedAt = readString(value['fetched_at']);
  const reviewStatus = readString(value['review_status']);

  if (
    id === null ||
    title === null ||
    publisher === null ||
    fetchedAt === null ||
    reviewStatus === null
  ) {
    return null;
  }

  return {
    id,
    title,
    publisher,
    fetchedAt,
    reviewStatus,
    reviewedBy: readOptionalString(value['reviewed_by']),
    reviewedAt: readOptionalString(value['reviewed_at']),
    stale: value['stale'] === true,
  };
}

export function parseAdminKnowledgeSources(value: unknown): readonly AdminKnowledgeSource[] | null {
  if (!Array.isArray(value)) {
    return null;
  }

  const rows: AdminKnowledgeSource[] = [];

  for (const item of value) {
    const parsed = parseSource(item);

    if (parsed === null) {
      return null;
    }

    rows.push(parsed);
  }

  return rows;
}

export function parseAdminKnowledgeSource(value: unknown): AdminKnowledgeSource | null {
  return parseSource(value);
}

/** Thai label for the review chip. Unknown values fail closed as unreviewed. */
export function reviewStatusLabel(status: string): string {
  return status === 'reviewed' ? 'ผ่านการตรวจแล้ว' : 'ยังไม่ตรวจ';
}
