import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { DataExportService } from './data-export.service';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const EXPORTS_PATH = '/api/v1/workspaces/ws-1/profiles/profile-1/exports';

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

describe('DataExportService', () => {
  let service: DataExportService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    service = TestBed.inject(DataExportService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('request() posts to the profile exports route and returns id/status', async () => {
    const pending = service.request('profile-1');
    const request = httpMock.expectOne(EXPORTS_PATH);

    expect(request.request.method).toBe('POST');

    request.flush(
      { data: { id: 'export-1', status: 'queued', object_key: 'never-exposed' } },
      { status: 202, statusText: 'Accepted' },
    );

    await expect(pending).resolves.toEqual({ id: 'export-1', status: 'queued' });
  });

  it('status() maps size and expiry but never keeps an object key', async () => {
    const pending = service.status('profile-1', 'export-1');
    const request = httpMock.expectOne(`${EXPORTS_PATH}/export-1`);

    expect(request.request.method).toBe('GET');

    request.flush({
      data: {
        id: 'export-1',
        status: 'ready',
        expires_at: '2026-10-26T10:00:00+00:00',
        byte_size: 2048,
        error_code: null,
        object_key: 'exports/tenant-1/export-1.json',
      },
    });

    const snapshot = await pending;

    expect(snapshot).toEqual({
      id: 'export-1',
      status: 'ready',
      expires_at: '2026-10-26T10:00:00+00:00',
      byte_size: 2048,
      error_code: null,
    });
    expect(snapshot).not.toHaveProperty('object_key');
  });

  it('link() posts to the link route and returns the short-lived URL', async () => {
    const pending = service.link('profile-1', 'export-1');
    const request = httpMock.expectOne(`${EXPORTS_PATH}/export-1/link`);

    expect(request.request.method).toBe('POST');

    request.flush({
      data: {
        url: 'https://files.example.test/exports/export-1?signature=abc',
        expires_at: '2026-10-25T10:15:00+00:00',
      },
    });

    await expect(pending).resolves.toEqual({
      url: 'https://files.example.test/exports/export-1?signature=abc',
      expires_at: '2026-10-25T10:15:00+00:00',
    });
  });

  it('maps export_rate_limited to a typed failure carrying the wait', async () => {
    const pending = service.request('profile-1');

    httpMock.expectOne(EXPORTS_PATH).flush(
      {
        code: 'export_rate_limited',
        detail: 'มีการขอส่งออกข้อมูลบ่อยเกินไป กรุณาลองใหม่ภายหลัง',
        retry_after_seconds: 3600,
      },
      { status: 429, statusText: 'Too Many Requests' },
    );

    await expect(pending).rejects.toMatchObject({
      code: 'rate_limited',
      status: 429,
      retryAfterSeconds: 3600,
    });
  });

  it('maps a 403 export_not_permitted response to not_permitted', async () => {
    const pending = service.request('profile-1');

    httpMock
      .expectOne(EXPORTS_PATH)
      .flush(
        { code: 'export_not_permitted', detail: 'ไม่ได้รับอนุญาตให้ส่งออกข้อมูลนี้' },
        { status: 403, statusText: 'Forbidden' },
      );

    await expect(pending).rejects.toMatchObject({
      code: 'not_permitted',
      status: 403,
    });
  });

  it('maps a 410 export_expired response to expired', async () => {
    const pending = service.link('profile-1', 'export-1');

    httpMock
      .expectOne(`${EXPORTS_PATH}/export-1/link`)
      .flush(
        { code: 'export_expired', detail: 'ไฟล์ส่งออกนี้หมดอายุแล้ว กรุณาขอใหม่' },
        { status: 410, statusText: 'Gone' },
      );

    await expect(pending).rejects.toMatchObject({
      code: 'expired',
      status: 410,
    });
  });

  it('maps a 409 export_not_ready response to not_ready', async () => {
    const pending = service.link('profile-1', 'export-1');

    httpMock
      .expectOne(`${EXPORTS_PATH}/export-1/link`)
      .flush(
        { code: 'export_not_ready', detail: 'ไฟล์ส่งออกยังไม่พร้อม กรุณาลองใหม่ภายหลัง' },
        { status: 409, statusText: 'Conflict' },
      );

    await expect(pending).rejects.toMatchObject({
      code: 'not_ready',
      status: 409,
    });
  });
});
