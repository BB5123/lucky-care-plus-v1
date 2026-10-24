import { HttpEvent, HttpEventType, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../core/auth/auth.models';
import { TaskEvidence } from '../../core/caretask/caretask.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { EvidenceForm } from './evidence-form';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const UPLOAD_PATH = '/api/v1/workspaces/ws-1/profiles/profile-1/documents';
const EVIDENCE_PATH = '/api/v1/workspaces/ws-1/care-tasks/task-1/evidence';

const DOCUMENT_PAYLOAD = {
  id: 'doc-1',
  kind: 'photo',
  mime_type: 'image/jpeg',
  size_bytes: 11,
  captured_at: null,
  status: 'available',
};

const EVIDENCE_PAYLOAD = {
  id: 'evidence-1',
  type: 'photo',
  metric: null,
  value: null,
  unit: null,
  document_id: 'doc-1',
  note: null,
  created_by: 7,
  created_at: '2026-10-24T09:00:00+07:00',
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function query(fixture: ComponentFixture<EvidenceForm>, selector: string): HTMLElement {
  return fixture.nativeElement.querySelector(selector) as HTMLElement;
}

describe('EvidenceForm photo evidence', () => {
  let httpMock: HttpTestingController;
  let fixture: ComponentFixture<EvidenceForm>;
  let saved: TaskEvidence[];

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [EvidenceForm],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(EvidenceForm);
    fixture.componentRef.setInput('taskId', 'task-1');
    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.componentRef.setInput('version', 3);
    fixture.detectChanges();

    saved = [];
    fixture.componentInstance.saved.subscribe((item) => saved.push(item));
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  function selectType(type: string): void {
    const select = query(fixture, '[data-testid="evidence-type"]') as HTMLSelectElement;

    select.value = type;
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  function chooseFile(file: File): void {
    const input = query(fixture, '[data-testid="evidence-photo-input"]') as HTMLInputElement;

    Object.defineProperty(input, 'files', {
      configurable: true,
      value: {
        0: file,
        length: 1,
        item: (index: number) => (index === 0 ? file : null),
      },
    });
    input.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  function submit(): void {
    const form = query(fixture, 'form') as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  function photoRequest() {
    return httpMock.expectOne((item) => item.method === 'POST' && item.url === UPLOAD_PATH);
  }

  async function attach(file: File): Promise<void> {
    selectType('photo');
    chooseFile(file);
    submit();

    photoRequest().flush({ data: DOCUMENT_PAYLOAD }, { status: 201, statusText: 'Created' });

    await nextTick();
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.method === 'POST' && item.url === EVIDENCE_PATH)
      .flush({ data: EVIDENCE_PAYLOAD }, { status: 201, statusText: 'Created' });

    await nextTick();
    fixture.detectChanges();
  }

  it('FE7-T02 a 422 surfaces the server Thai message and creates no evidence row', async () => {
    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });

    selectType('photo');
    chooseFile(file);
    submit();

    photoRequest().flush(
      {
        code: 'validation',
        status: 422,
        detail: 'ข้อมูลไม่ถูกต้อง',
        errors: { file: ['ไฟล์ต้องมีขนาดไม่เกิน 10485760 ไบต์'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    await nextTick();
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="evidence-photo-error"]').textContent).toContain(
      'ไฟล์ต้องมีขนาดไม่เกิน 10485760 ไบต์',
    );
    httpMock.expectNone((item) => item.url === EVIDENCE_PATH);
    expect(saved).toHaveLength(0);
  });

  it('FE7-T03 a 503 scan_unavailable shows the scanner message and creates no evidence row', async () => {
    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });

    selectType('photo');
    chooseFile(file);
    submit();

    photoRequest().flush(
      {
        code: 'scan_unavailable',
        status: 503,
        detail: 'ไม่สามารถตรวจสอบไฟล์ได้ในขณะนี้',
      },
      { status: 503, statusText: 'Service Unavailable' },
    );

    await nextTick();
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="evidence-photo-error"]').textContent).toContain(
      'ระบบตรวจไวรัสไม่พร้อมใช้งาน จึงยังอัปโหลดไม่ได้',
    );
    httpMock.expectNone((item) => item.url === EVIDENCE_PATH);
    expect(saved).toHaveLength(0);
  });

  it('FE7-T04 a successful upload then creates the evidence row with document_id and type=photo', async () => {
    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });

    selectType('photo');
    chooseFile(file);
    submit();

    photoRequest().flush({ data: DOCUMENT_PAYLOAD }, { status: 201, statusText: 'Created' });

    await nextTick();
    fixture.detectChanges();

    const evidence = httpMock.expectOne(
      (item) => item.method === 'POST' && item.url === EVIDENCE_PATH,
    );

    expect(evidence.request.body).toEqual({ type: 'photo', document_id: 'doc-1' });

    evidence.flush({ data: EVIDENCE_PAYLOAD }, { status: 201, statusText: 'Created' });

    await nextTick();
    fixture.detectChanges();

    expect(saved).toHaveLength(1);
    expect(saved[0]?.document_id).toBe('doc-1');

    const attached = query(fixture, '[data-testid="evidence-photo-attached"]');

    expect(attached.textContent).toContain('pressure.jpg');
    expect(attached.textContent).toContain('ดูไฟล์');
  });

  it('FE7-T05 "ดูไฟล์" requests a signed URL only on click and never renders it', async () => {
    await attach(new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' }));

    // Nothing was requested eagerly and no capability URL reached the DOM.
    httpMock.expectNone((item) => item.url.endsWith('/documents/doc-1/url'));
    expect(fixture.nativeElement.innerHTML).not.toContain('signed.example');

    const open = vi.spyOn(window, 'open').mockReturnValue(null);

    try {
      query(fixture, '[data-testid="evidence-photo-open"]').click();
      fixture.detectChanges();

      const request = httpMock.expectOne(
        (item) =>
          item.method === 'POST' &&
          item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/documents/doc-1/url',
      );

      request.flush({
        data: {
          document_id: 'doc-1',
          url: 'https://signed.example/document?signature=abc',
          expires_at: '2026-10-24T09:05:00+07:00',
        },
      });

      await nextTick();
      fixture.detectChanges();

      expect(open).toHaveBeenCalledWith('https://signed.example/document?signature=abc', '_blank');
      expect(fixture.nativeElement.innerHTML).not.toContain('signed.example');
    } finally {
      open.mockRestore();
    }
  });

  it('FE7-T07 upload progress disables submit while in flight', async () => {
    selectType('photo');
    chooseFile(new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' }));
    submit();

    const submitButton = query(fixture, '[data-testid="evidence-submit"]') as HTMLButtonElement;
    const request = photoRequest();

    expect(submitButton.disabled).toBe(true);

    request.event({
      type: HttpEventType.UploadProgress,
      loaded: 4,
      total: 10,
    } as HttpEvent<unknown>);
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="evidence-upload-progress"]').textContent).toContain('40%');
    expect(submitButton.disabled).toBe(true);
    httpMock.expectNone((item) => item.url === EVIDENCE_PATH);

    request.flush({ data: DOCUMENT_PAYLOAD }, { status: 201, statusText: 'Created' });

    await nextTick();
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.url === EVIDENCE_PATH)
      .flush({ data: EVIDENCE_PAYLOAD }, { status: 201, statusText: 'Created' });

    await nextTick();
    fixture.detectChanges();

    expect(submitButton.disabled).toBe(false);
  });
});
