import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { httpStatus, toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';

interface EntityEnvelope {
  readonly data?: unknown;
}

/**
 * The lifecycle values the export API exposes. `revoked` is a terminal state
 * the client must treat like "no longer permitted", not like a transient
 * failure.
 */
export type DataExportStatusValue =
  'queued' | 'building' | 'ready' | 'failed' | 'expired' | 'revoked';

export interface DataExportRequestResult {
  readonly id: string;
  readonly status: string;
}

/**
 * The status resource. It deliberately has no `object_key` and no URL: the
 * file is addressed only through a freshly minted, short-lived link.
 */
export interface DataExportSnapshot {
  readonly id: string;
  readonly status: string;
  readonly expires_at: string | null;
  readonly byte_size: number | null;
  readonly error_code: string | null;
}

export interface DataExportLink {
  readonly url: string;
  readonly expires_at: string | null;
}

export type DataExportFailureCode =
  'rate_limited' | 'not_permitted' | 'expired' | 'not_ready' | 'unknown';

const GENERIC_FAILURE_MESSAGE = 'ไม่สามารถดำเนินการกับสำเนาข้อมูลได้ในขณะนี้';
const RATE_LIMITED_MESSAGE = 'มีการขอสำเนาข้อมูลบ่อยเกินไป กรุณารอตามเวลาที่แจ้ง';
const NOT_PERMITTED_MESSAGE = 'สิทธิ์การเข้าถึงปัจจุบันไม่รวมการขอสำเนาข้อมูล';
const EXPIRED_MESSAGE = 'สำเนาข้อมูลนี้หมดอายุแล้ว กรุณาขอใหม่';
const NOT_READY_MESSAGE = 'สำเนาข้อมูลยังจัดเตรียมไม่เสร็จ กรุณาลองใหม่ภายหลัง';

/**
 * The export request window is 24 hours by default. The server always sends
 * the real remaining time; this is only the fallback when a proxy strips both
 * the body field and `Retry-After`.
 */
export const DEFAULT_EXPORT_RATE_LIMIT_SECONDS = 24 * 60 * 60;

/**
 * A typed failure for the four problems the export API names explicitly.
 *
 * `retryAfterSeconds` is populated only for `rate_limited`, so the card can
 * say when another request is possible without parsing the server sentence.
 */
export class DataExportError extends Error {
  constructor(
    readonly code: DataExportFailureCode,
    readonly status: number | null,
    message: string,
    readonly retryAfterSeconds: number | null = null,
  ) {
    super(message);
    this.name = 'DataExportError';
  }
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

function problemPayload(error: unknown): Record<string, unknown> | null {
  if (!(error instanceof HttpErrorResponse) || !isRecord(error.error)) {
    return null;
  }

  return error.error;
}

function problemCode(error: unknown): string | null {
  const payload = problemPayload(error);

  return payload === null ? null : readString(payload['code']);
}

function readRetryAfter(
  error: HttpErrorResponse,
  payload: Record<string, unknown> | null,
): number | null {
  const bodyValue = payload === null ? undefined : payload['retry_after_seconds'];

  if (typeof bodyValue === 'number' && Number.isFinite(bodyValue) && bodyValue >= 0) {
    return Math.round(bodyValue);
  }

  if (typeof bodyValue === 'string' && bodyValue.trim() !== '') {
    const parsed = Number(bodyValue);

    if (Number.isFinite(parsed) && parsed >= 0) {
      return Math.round(parsed);
    }
  }

  const header = error.headers?.get('Retry-After') ?? null;

  if (header !== null && header.trim() !== '') {
    const parsed = Number(header);

    if (Number.isFinite(parsed) && parsed >= 0) {
      return Math.round(parsed);
    }
  }

  return null;
}

function toFailure(error: unknown): DataExportError {
  if (error instanceof DataExportError) {
    return error;
  }

  const status = httpStatus(error);
  const code = problemCode(error);

  if (code === 'export_rate_limited' || status === 429) {
    const retryAfter =
      error instanceof HttpErrorResponse
        ? (readRetryAfter(error, problemPayload(error)) ?? DEFAULT_EXPORT_RATE_LIMIT_SECONDS)
        : DEFAULT_EXPORT_RATE_LIMIT_SECONDS;

    return new DataExportError('rate_limited', status, RATE_LIMITED_MESSAGE, retryAfter);
  }

  if (code === 'export_not_permitted' || status === 403) {
    return new DataExportError('not_permitted', status, NOT_PERMITTED_MESSAGE);
  }

  if (code === 'export_expired' || status === 410) {
    return new DataExportError('expired', status, EXPIRED_MESSAGE);
  }

  if (code === 'export_not_ready' || status === 409) {
    return new DataExportError('not_ready', status, NOT_READY_MESSAGE);
  }

  return new DataExportError('unknown', status, toUserMessage(error) || GENERIC_FAILURE_MESSAGE);
}

function parseRequest(value: unknown): DataExportRequestResult | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);
  const status = readString(value['status']);

  if (id === null || id === '' || status === null || status === '') {
    return null;
  }

  return { id, status };
}

function parseSnapshot(value: unknown): DataExportSnapshot | null {
  if (!isRecord(value)) {
    return null;
  }

  const id = readString(value['id']);
  const status = readString(value['status']);

  if (id === null || id === '' || status === null || status === '') {
    return null;
  }

  return {
    id,
    status,
    expires_at: readString(value['expires_at']),
    byte_size: readNumber(value['byte_size']),
    error_code: readString(value['error_code']),
  };
}

function parseLink(value: unknown): DataExportLink | null {
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

/**
 * Client for the PDPA data-export surface
 * (`.../workspaces/{workspace}/profiles/{profile}/exports`).
 *
 * The service never stores a signed URL: `link()` returns a short-lived
 * capability that the caller must open immediately. It also never exposes an
 * object key, because the API resource does not return one.
 */
@Injectable({ providedIn: 'root' })
export class DataExportService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  /** Queue an export for one profile. */
  async request(profileId: string): Promise<DataExportRequestResult> {
    try {
      const response = await firstValueFrom(
        this.http.post<EntityEnvelope>(this.exportsPath(profileId), {}),
      );
      const result = parseRequest(response?.data);

      if (result === null) {
        throw new DataExportError('unknown', null, 'ไม่สามารถอ่านสถานะคำขอสำเนาข้อมูลได้');
      }

      return result;
    } catch (error) {
      throw toFailure(error);
    }
  }

  /** Read the current status; safe to poll while the job is queued/building. */
  async status(profileId: string, id: string): Promise<DataExportSnapshot> {
    try {
      const response = await firstValueFrom(
        this.http.get<EntityEnvelope>(`${this.exportsPath(profileId)}/${encodeURIComponent(id)}`),
      );
      const snapshot = parseSnapshot(response?.data);

      if (snapshot === null) {
        throw new DataExportError('unknown', null, 'ไม่สามารถอ่านสถานะสำเนาข้อมูลได้');
      }

      return snapshot;
    } catch (error) {
      throw toFailure(error);
    }
  }

  /**
   * Mint a short-lived signed link. The URL lives only in the resolved value;
   * caching it would extend a capability past its TTL.
   */
  async link(profileId: string, id: string): Promise<DataExportLink> {
    try {
      const response = await firstValueFrom(
        this.http.post<EntityEnvelope>(
          `${this.exportsPath(profileId)}/${encodeURIComponent(id)}/link`,
          {},
        ),
      );
      const link = parseLink(response?.data);

      if (link === null) {
        throw new DataExportError('unknown', null, 'ไม่สามารถเปิดลิงก์ดาวน์โหลดได้ในขณะนี้');
      }

      return link;
    } catch (error) {
      throw toFailure(error);
    }
  }

  private exportsPath(profileId: string): string {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null || workspaceId === '') {
      throw new DataExportError('unknown', null, 'ไม่พบพื้นที่ทำงานสำหรับขอสำเนาข้อมูล');
    }

    if (profileId === '') {
      throw new DataExportError('unknown', null, 'ไม่พบโปรไฟล์สำหรับขอสำเนาข้อมูล');
    }

    return `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/profiles/${encodeURIComponent(profileId)}/exports`;
  }
}
