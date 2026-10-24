/**
 * Typed mirror of `GET /workspaces/{workspace}/profiles`.
 *
 * The endpoint answers one narrow question: which health profiles may this
 * actor enter? It exposes no clinical data — only an id, the owning person, a
 * display name, and two server-decided labels. Parsing is defensive: a
 * malformed row is ignored, never thrown.
 */
export interface HealthProfileSummary {
  readonly id: string;
  readonly person_id: string;
  readonly display_name: string;
  /** Actor-relative: `self`, `dependent` or `other`. */
  readonly relationship: string;
  /** Actor-independent: `self` when the person owns an account. */
  readonly kind: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

export function parseProfile(value: unknown): HealthProfileSummary | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);

  if (id === null || id === '') {
    return null;
  }

  return {
    id,
    person_id: readString(value['person_id']) ?? '',
    display_name: readString(value['display_name']) ?? '',
    relationship: readString(value['relationship']) ?? 'other',
    kind: readString(value['kind']) ?? 'dependent',
  };
}

export function parseProfiles(value: unknown): readonly HealthProfileSummary[] {
  const rows = Array.isArray(value) ? value : [];

  return rows
    .map((row) => parseProfile(row))
    .filter((row): row is HealthProfileSummary => row !== null);
}

/**
 * The server already orders `self` first, but the client keeps the preference
 * explicit so an ordering change cannot open a dependent instead of the
 * actor's own profile. An empty list is a normal answer.
 */
export function selectCurrentProfile(
  profiles: readonly HealthProfileSummary[],
): HealthProfileSummary | null {
  return profiles.find((profile) => profile.relationship === 'self') ?? profiles[0] ?? null;
}
