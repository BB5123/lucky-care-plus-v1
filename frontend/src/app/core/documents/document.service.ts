import { HttpClient, HttpErrorResponse, HttpEventType, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { filter, tap } from 'rxjs/operators';
import { API_BASE_URL } from '../api/api-base.token';
import { httpStatus, readFieldErrors, toUserMessage } from '../api/problem-details';
import { WorkspaceService } from '../workspace/workspace.service';
import {
  DocumentKind,
  DocumentSignedUrl,
  MedicalDocument,
  parseDocumentSignedUrl,
  parseMedicalDocument,
} from './document.models';

interface EntityEnvelope {
  readonly data?: unknown;
}

/**
 * The upload was refused. `code` is the API problem code where one exists, so
 * the UI can explain a fail-closed scanner outage differently from a bad file.
 */
export type DocumentUploadFailureCode =
  'validation' | 'scan_unavailable' | 'storage_unavailable' | 'forbidden' | 'not_found' | 'unknown';

/**
 * A distinct, typed upload failure. A 422 keeps the server's own sentence
 * (`errors.file` first); a 503 `scan_unavailable` becomes the product sentence
 * required by the task screen. The screen never shows a raw stack or status.
 */
export class DocumentUploadError extends Error {
  constructor(
    readonly code: DocumentUploadFailureCode,
    readonly status: number | null,
    message: string,
  ) {
    super(message);
    this.name = 'DocumentUploadError';
  }
}

/** The backend refuses uploads while no scanner is deployed (F-09). */
export const SCAN_UNAVAILABLE_MESSAGE = 'ระบบตรวจไวรัสไม่พร้อมใช้งาน จึงยังอัปโหลดไม่ได้';

const STORAGE_UNAVAILABLE_MESSAGE = 'ไม่สามารถจัดเก็บไฟล์ได้ในขณะนี้';

function problemCode(error: unknown): string | null {
  if (!(error instanceof HttpErrorResponse)) {
    return null;
  }

  const payload = error.error;

  if (typeof payload !== 'object' || payload === null) {
    return null;
  }

  const code = (payload as Record<string, unknown>)['code'];

  return typeof code === 'string' ? code : null;
}

function uploadFailure(error: unknown): DocumentUploadError {
  if (error instanceof DocumentUploadError) {
    return error;
  }

  const status = httpStatus(error);
  const code = problemCode(error);
  const fileMessage = readFieldErrors(error)['file'] ?? null;

  if (code === 'scan_unavailable') {
    return new DocumentUploadError('scan_unavailable', status, SCAN_UNAVAILABLE_MESSAGE);
  }

  if (code === 'storage_unavailable') {
    return new DocumentUploadError('storage_unavailable', status, STORAGE_UNAVAILABLE_MESSAGE);
  }

  if (status === 422) {
    // The size limit is rendered by the server in Thai under `errors.file`;
    // a generic `detail` is only the fallback.
    return new DocumentUploadError('validation', status, fileMessage ?? toUserMessage(error));
  }

  if (status === 403) {
    return new DocumentUploadError('forbidden', status, toUserMessage(error));
  }

  if (status === 404) {
    return new DocumentUploadError('not_found', status, toUserMessage(error));
  }

  return new DocumentUploadError('unknown', status, toUserMessage(error));
}

/**
 * Medical-document upload and on-demand signed links.
 *
 * There is deliberately no `delete`: the baseline denies document deletion by
 * default, so the UI must not offer it. Files are sent as `multipart/form-data`
 * with the field `file` and the document `kind`; the server sniffs the actual
 * bytes and owns every validation decision.
 *
 * The signed URL is never cached: the caller opens it immediately and it
 * expires on the server's TTL.
 */
@Injectable({ providedIn: 'root' })
export class DocumentService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  /**
   * The document routes are profile-scoped. Only the upload action knows the
   * profile in this slice (the evidence form always uploads before it offers
   * "ดูไฟล์"), so the successful upload remembers the profile that owns the
   * link.
   */
  private uploadedProfileId: string | null = null;

  /**
   * Uploads one file and reports `HttpEventType.UploadProgress` as a rounded
   * percentage. The resolved value is the created document metadata.
   */
  async upload(
    profileId: string,
    file: File,
    kind: DocumentKind,
    onProgress?: (percent: number) => void,
  ): Promise<MedicalDocument> {
    const workspaceId = this.requireWorkspace();

    if (profileId === '') {
      throw new DocumentUploadError('unknown', null, 'ไม่พบโปรไฟล์สำหรับอัปโหลดไฟล์');
    }

    const body = new FormData();
    body.append('file', file, file.name);
    body.append('kind', kind);

    try {
      const response = await firstValueFrom(
        this.http
          .post<EntityEnvelope>(this.documentsPath(workspaceId, profileId), body, {
            reportProgress: true,
            observe: 'events',
          })
          .pipe(
            tap((event) => {
              if (
                event.type === HttpEventType.UploadProgress &&
                event.total !== undefined &&
                event.total > 0
              ) {
                onProgress?.(Math.min(100, Math.round((event.loaded / event.total) * 100)));
              }
            }),
            filter(
              (event): event is HttpResponse<EntityEnvelope> =>
                event.type === HttpEventType.Response,
            ),
          ),
      );
      const document = parseMedicalDocument(response.body?.data);

      if (document === null) {
        throw new DocumentUploadError(
          'unknown',
          response.status,
          'ไม่สามารถอ่านข้อมูลไฟล์ที่อัปโหลดได้',
        );
      }

      this.uploadedProfileId = profileId;

      return document;
    } catch (error) {
      throw uploadFailure(error);
    }
  }

  /**
   * Issues a short-lived signed link. The caller must open it immediately;
   * storing it would extend a capability past its TTL.
   *
   * The route is profile-scoped and the profile is known from the upload that
   * created the document; "ดูไฟล์" is only offered for a document uploaded in
   * the same form.
   */
  async signedUrl(documentId: string): Promise<DocumentSignedUrl> {
    const workspaceId = this.requireWorkspace();
    const resolvedProfile = this.uploadedProfileId;

    if (resolvedProfile === null || resolvedProfile === '') {
      throw new DocumentUploadError('unknown', null, 'ไม่พบโปรไฟล์สำหรับเปิดไฟล์');
    }

    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        `${this.documentsPath(workspaceId, resolvedProfile)}/${encodeURIComponent(documentId)}/url`,
        {},
      ),
    );
    const signed = parseDocumentSignedUrl(response.data);

    if (signed === null) {
      throw new DocumentUploadError('unknown', null, 'ไม่สามารถเปิดไฟล์ได้ในขณะนี้');
    }

    return signed;
  }

  private documentsPath(workspaceId: string, profileId: string): string {
    return `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/profiles/${encodeURIComponent(profileId)}/documents`;
  }

  private workspaceId(): string | null {
    return this.workspace.currentWorkspace()?.workspace_id ?? null;
  }

  private requireWorkspace(): string {
    const workspaceId = this.workspaceId();

    if (workspaceId === null) {
      throw new DocumentUploadError('unknown', null, 'ไม่พบพื้นที่ทำงานสำหรับอัปโหลดไฟล์');
    }

    return workspaceId;
  }
}
