import { HttpErrorResponse } from '@angular/common/http';

/**
 * RFC 9457 `application/problem+json` payload returned by the API.
 *
 * The API keeps the human-readable Thai sentence in `detail`; some gateway
 * layers add `message`. Both are server-controlled, so neither can contain a
 * stack trace or private health data (N-02). The client only renders them.
 */
export interface ProblemDetails {
  type?: string;
  title?: string;
  status?: number;
  code?: string;
  detail?: string;
  message?: string;
  trace_id?: string;
  errors?: Record<string, string[]>;
}

/** A single server-authored sentence per field name (e.g. `value`, `code`). */
export type FieldErrors = Readonly<Record<string, string>>;

const GENERIC_MESSAGE = 'เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่อีกครั้ง';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

/**
 * Stack traces and framework internals must never reach the UI. Server
 * sentences are short; anything matching these patterns is discarded.
 */
function looksLikeInternalDetail(text: string): boolean {
  return /(\bat\s+\w+\s+\(|stack trace|traceback|\/vendor\/|\.php:\d+|\.ts:\d+)/i.test(text);
}

function readSafeString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();

  if (trimmed === '' || looksLikeInternalDetail(trimmed)) {
    return null;
  }

  return trimmed;
}

function readProblemMessage(value: unknown): string | null {
  // `Error` also exposes a `message` property, but its text is developer-facing
  // (and may include internals), so it is never treated as a problem payload.
  if (!isRecord(value) || value instanceof Error) {
    return null;
  }

  // Prefer the explicit `message` field; the Phase 1 envelope carries the same
  // safe Thai sentence in `detail`, so use it as the secondary source.
  return readSafeString(value['message']) ?? readSafeString(value['detail']);
}

/**
 * Turns any thrown value into a Thai sentence that is safe to show.
 *
 * Order: API `message` → API `detail` → generic Thai fallback. A stack trace,
 * HTML error page or raw JavaScript error is never surfaced.
 */
export function toUserMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    // Only the response body can carry a server-authored safe sentence.
    return readProblemMessage(error.error) ?? GENERIC_MESSAGE;
  }

  if (error instanceof Error) {
    return GENERIC_MESSAGE;
  }

  return readProblemMessage(error) ?? GENERIC_MESSAGE;
}

/**
 * Returns the HTTP status of a thrown `HttpErrorResponse`, or `null` when the
 * value is not an HTTP failure. Components use it for 412 (version conflict)
 * and 422 (validation) handling.
 */
export function httpStatus(error: unknown): number | null {
  return error instanceof HttpErrorResponse ? error.status : null;
}

/**
 * Narrows the `errors{}` map of a RFC 9457 problem payload.
 *
 * Each field keeps only its first safe sentence; framework internals and
 * non-string entries are discarded. The result is bound to the matching form
 * control so a 422 is never reduced to a generic toast.
 */
export function readFieldErrors(error: unknown): FieldErrors {
  if (!(error instanceof HttpErrorResponse)) {
    return {};
  }

  const payload = error.error;

  if (!isRecord(payload) || payload instanceof Error) {
    return {};
  }

  const rawErrors = payload['errors'];

  if (!isRecord(rawErrors)) {
    return {};
  }

  const fieldErrors: Record<string, string> = {};

  for (const [field, value] of Object.entries(rawErrors)) {
    const first = Array.isArray(value)
      ? value.find((entry): entry is string => typeof entry === 'string')
      : value;
    const safe = readSafeString(first);

    if (safe !== null) {
      fieldErrors[field] = safe;
    }
  }

  return fieldErrors;
}
