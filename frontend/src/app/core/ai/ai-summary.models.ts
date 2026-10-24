/**
 * AI summary contract (TASK P-1 / G4-UI).
 *
 * The API returns `{data: {summary, disclaimer, record_refs, usage, model}}`.
 * The payload is untrusted server output: the parser accepts known fields only
 * and rejects a malformed envelope instead of rendering `undefined`.
 */
export interface AiSummaryRecordRef {
  readonly type: string;
  readonly id: string;
}

export interface AiSummaryUsage {
  readonly requestsToday: number;
  readonly remaining: number;
}

export interface AiSummary {
  readonly summary: string;
  readonly disclaimer: string;
  readonly recordRefs: readonly AiSummaryRecordRef[];
  readonly usage: AiSummaryUsage;
  readonly model: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function readString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
}

function readRecordRefs(value: unknown): readonly AiSummaryRecordRef[] | null {
  if (!Array.isArray(value)) {
    return null;
  }

  const refs: AiSummaryRecordRef[] = [];

  for (const item of value) {
    if (!isRecord(item)) {
      return null;
    }

    const type = readString(item['type']);
    const id = readString(item['id']);

    if (type === null || id === null) {
      return null;
    }

    refs.push({ type, id });
  }

  return refs;
}

export function parseAiSummary(value: unknown): AiSummary | null {
  if (!isRecord(value)) {
    return null;
  }

  const summary = readString(value['summary']);
  const disclaimer = readString(value['disclaimer']);
  const model = readString(value['model']);
  const recordRefs = readRecordRefs(value['record_refs']);
  const usage = value['usage'];

  if (
    summary === null ||
    disclaimer === null ||
    model === null ||
    recordRefs === null ||
    !isRecord(usage)
  ) {
    return null;
  }

  const requestsToday = readNumber(usage['requests_today']);
  const remaining = readNumber(usage['remaining']);

  if (requestsToday === null || remaining === null) {
    return null;
  }

  return {
    summary,
    disclaimer,
    recordRefs,
    usage: { requestsToday, remaining },
    model,
  };
}
