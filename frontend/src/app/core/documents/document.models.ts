/**
 * Typed mirror of the medical-document API
 * (`.../workspaces/{workspace}/profiles/{profile}/documents`).
 *
 * The upload acknowledgement deliberately omits `original_name`: the filename
 * may contain a person's name and upload permission is not read permission
 * (`.ai/09-SECURITY-PRIVACY.md` §2.4). The parser therefore treats it as
 * nullable instead of inventing a value.
 *
 * Nothing here ever carries a storage path, disk or signed URL. A document is
 * addressed by its id; a short-lived link is issued only on demand by
 * `DocumentService.signedUrl()`.
 */

export const DOCUMENT_KINDS = [
  'lab_result',
  'prescription',
  'discharge_summary',
  'photo',
  'other',
] as const;

export type DocumentKind = (typeof DOCUMENT_KINDS)[number];

export interface MedicalDocument {
  readonly id: string;
  readonly kind: string;
  readonly mime_type: string;
  readonly size_bytes: number | null;
  readonly original_name: string | null;
  readonly captured_at: string | null;
  readonly status: string;
}

export interface DocumentSignedUrl {
  readonly url: string;
  readonly expires_at: string | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

export function isDocumentKind(value: string): value is DocumentKind {
  return (DOCUMENT_KINDS as readonly string[]).includes(value);
}

/**
 * A payload without an id is not a document. Every other field falls back to
 * a safe default so a partial response can never crash the screen.
 */
export function parseMedicalDocument(value: unknown): MedicalDocument | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);

  if (id === null || id === '') {
    return null;
  }

  return {
    id,
    kind: readString(value['kind']) ?? 'other',
    mime_type: readString(value['mime_type']) ?? 'application/octet-stream',
    size_bytes: readNumber(value['size_bytes']),
    original_name: readString(value['original_name']),
    captured_at: readString(value['captured_at']),
    status: readString(value['status']) ?? 'available',
  };
}

/**
 * Parses `POST .../documents/{id}/url`. The URL is a short-lived capability
 * and is never persisted; only the caller that opens the file sees it.
 */
export function parseDocumentSignedUrl(value: unknown): DocumentSignedUrl | null {
  if (!isRecord(value)) {
    return null;
  }

  const url = readString(value['url']);

  if (url === null || url === '') {
    return null;
  }

  return {
    url,
    expires_at: readString(value['expires_at']),
  };
}
