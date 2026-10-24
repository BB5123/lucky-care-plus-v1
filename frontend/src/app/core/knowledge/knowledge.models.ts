/**
 * Knowledge-answer contract (TASK G4-KB / web UI).
 *
 * The API returns `{data: {answer, sources, disclaimer, model, usage}}`.
 * Sources are public references; the parser keeps only known fields and
 * rejects a malformed envelope.
 */
export interface KnowledgeSource {
  readonly label: string;
  readonly id: string;
  readonly title: string;
  readonly publisher: string;
  readonly url: string;
  readonly publishedAt: string | null;
  readonly fetchedAt: string;
  readonly reviewStatus: string;
}

export interface KnowledgeAnswer {
  readonly answer: string;
  readonly sources: readonly KnowledgeSource[];
  readonly disclaimer: string;
  readonly model: string;
  readonly usage: {
    readonly requestsToday: number;
    readonly remaining: number;
  };
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

function readSources(value: unknown): readonly KnowledgeSource[] | null {
  if (!Array.isArray(value)) {
    return null;
  }

  const sources: KnowledgeSource[] = [];

  for (const item of value) {
    if (!isRecord(item)) {
      return null;
    }

    const label = readString(item['label']);
    const id = readString(item['id']);
    const title = readString(item['title']);
    const publisher = readString(item['publisher']);
    const url = readString(item['url']);
    const fetchedAt = readString(item['fetched_at']);

    if (
      label === null ||
      id === null ||
      title === null ||
      publisher === null ||
      url === null ||
      fetchedAt === null
    ) {
      return null;
    }

    const publishedAt = typeof item['published_at'] === 'string' ? item['published_at'] : null;
    const reviewStatus = typeof item['review_status'] === 'string' ? item['review_status'] : 'unreviewed';

    sources.push({
      label,
      id,
      title,
      publisher,
      url,
      publishedAt,
      fetchedAt,
      reviewStatus,
    });
  }

  return sources;
}

export function parseKnowledgeAnswer(value: unknown): KnowledgeAnswer | null {
  if (!isRecord(value)) {
    return null;
  }

  const answer = readString(value['answer']);
  const disclaimer = readString(value['disclaimer']);
  const model = readString(value['model']);
  const sources = readSources(value['sources']);
  const usage = value['usage'];

  if (
    answer === null ||
    disclaimer === null ||
    model === null ||
    sources === null ||
    sources.length === 0 ||
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
    answer,
    sources,
    disclaimer,
    model,
    usage: { requestsToday, remaining },
  };
}
