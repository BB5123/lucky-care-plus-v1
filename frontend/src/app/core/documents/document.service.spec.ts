import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { DocumentService } from './document.service';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

const DOCUMENT_PAYLOAD = {
  id: 'doc-1',
  kind: 'photo',
  mime_type: 'image/jpeg',
  size_bytes: 5,
  captured_at: null,
  status: 'available',
};

describe('DocumentService', () => {
  let httpMock: HttpTestingController;
  let service: DocumentService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    service = TestBed.inject(DocumentService);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE7-T01 upload() posts FormData containing the file to the profile documents path', async () => {
    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });
    const pending = service.upload('profile-1', file, 'photo');

    const request = httpMock.expectOne(
      (item) =>
        item.method === 'POST' &&
        item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/documents',
    );

    expect(request.request.body instanceof FormData).toBe(true);

    const body = request.request.body as FormData;
    const sent = body.get('file');

    expect(sent).toBeInstanceOf(File);
    expect((sent as File).name).toBe('pressure.jpg');
    expect((sent as File).type).toBe('image/jpeg');
    expect(body.get('kind')).toBe('photo');

    request.flush({ data: DOCUMENT_PAYLOAD }, { status: 201, statusText: 'Created' });

    const document = await pending;

    expect(document.id).toBe('doc-1');
    expect(document.kind).toBe('photo');
    expect(document.mime_type).toBe('image/jpeg');
  });

  it('maps a 503 scan_unavailable response to a typed failure', async () => {
    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });
    const pending = service.upload('profile-1', file, 'photo');

    httpMock
      .expectOne((item) => item.url.endsWith('/profiles/profile-1/documents'))
      .flush(
        {
          code: 'scan_unavailable',
          status: 503,
          detail: 'ไม่สามารถตรวจสอบไฟล์ได้ในขณะนี้',
        },
        { status: 503, statusText: 'Service Unavailable' },
      );

    await expect(pending).rejects.toMatchObject({
      code: 'scan_unavailable',
      message: 'ระบบตรวจไวรัสไม่พร้อมใช้งาน จึงยังอัปโหลดไม่ได้',
    });
  });

  it('signedUrl() posts to the document url route only when asked', async () => {
    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });
    const upload = service.upload('profile-1', file, 'photo');

    httpMock
      .expectOne((item) => item.url.endsWith('/profiles/profile-1/documents'))
      .flush({ data: DOCUMENT_PAYLOAD }, { status: 201, statusText: 'Created' });
    await upload;

    const pending = service.signedUrl('doc-1');

    const request = httpMock.expectOne(
      (item) =>
        item.method === 'POST' &&
        item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/documents/doc-1/url',
    );

    request.flush({
      data: {
        document_id: 'doc-1',
        url: '/api/v1/documents/doc-1/download?signature=abc',
        expires_at: '2026-10-24T10:05:00+00:00',
      },
    });

    await expect(pending).resolves.toEqual({
      url: '/api/v1/documents/doc-1/download?signature=abc',
      expires_at: '2026-10-24T10:05:00+00:00',
    });
  });
});
