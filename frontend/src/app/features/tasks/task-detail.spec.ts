import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { AuthUser } from '../../core/auth/auth.models';
import { MembershipSummary } from '../../core/auth/auth.models';
import { AuthService } from '../../core/auth/auth.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { TaskDetail } from './task-detail';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const USER: AuthUser = {
  id: '7',
  name: 'ผู้ใช้ทดสอบ',
  email: 'user@example.test',
  phone: null,
  phone_verified_at: null,
  is_platform_admin: false,
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);
  const members = signal([
    { user_id: 9, name: 'ผู้ช่วยทดสอบ', role: 'assistant', status: 'active' },
  ]);
  const membersLoading = signal(false);
  const membersError = signal<string | null>(null);

  return {
    currentWorkspace: current.asReadonly(),
    members: members.asReadonly(),
    membersLoading: membersLoading.asReadonly(),
    membersError: membersError.asReadonly(),
    loadMembers: () => Promise.resolve(),
  };
}

function authStub(): Partial<AuthService> {
  const user = signal<AuthUser | null>(USER);

  return { user: user.asReadonly() };
}

function baseTask(overrides: Record<string, unknown>): Record<string, unknown> {
  return {
    id: 'task-1',
    profile_id: 'profile-1',
    profile_display_name: 'คุณพ่อ',
    tier: 'delegated',
    type: 'MEDICATION_SUPPORT',
    title: 'งานทดสอบ',
    description: 'รายละเอียดงานทดสอบ',
    priority: 'normal',
    scheduled_for: '2026-10-23T09:00:00+07:00',
    due_at: '2026-10-23T17:00:00+07:00',
    timezone: 'Asia/Bangkok',
    status: 'in_progress',
    outcome: null,
    verification: 'not_required',
    verification_required: false,
    evidence_policy: 'none',
    escalation_level: 0,
    blocked_reason: null,
    review_at: null,
    completed_at: null,
    version: 1,
    source: 'manual',
    created_by: null,
    assignments: [],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('TaskDetail', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [TaskDetail],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: WorkspaceService, useValue: workspaceStub() },
        { provide: AuthService, useValue: authStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  async function render(
    payload: Record<string, unknown>,
    evidence: readonly unknown[] = [],
  ): Promise<ComponentFixture<TaskDetail>> {
    const fixture = TestBed.createComponent(TaskDetail);

    fixture.componentRef.setInput('taskId', 'task-1');
    fixture.detectChanges();

    httpMock.expectOne((item) => item.url.endsWith('/care-tasks/task-1')).flush({ data: payload });

    await nextTick();

    if (payload['tier'] === 'clinical') {
      httpMock
        .expectOne((item) => item.url.endsWith('/care-tasks/task-1/evidence'))
        .flush({ data: evidence });

      await nextTick();
    }

    fixture.detectChanges();

    return fixture;
  }

  function click(fixture: ComponentFixture<TaskDetail>, selector: string): void {
    const element = fixture.nativeElement.querySelector(selector) as HTMLElement | null;

    element?.click();
    fixture.detectChanges();
  }

  it('FE4-T07 a 409 renders the server domain sentence', async () => {
    const fixture = await render(baseTask({ status: 'in_progress' }));

    click(fixture, '[data-testid="task-action-complete"]');

    const form = fixture.nativeElement.querySelector(
      '[data-testid="task-complete-panel"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = httpMock.expectOne((item) => item.url.endsWith('/care-tasks/task-1/complete'));

    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('If-Match')).toBe('1');

    request.flush(
      {
        type: 'https://api.luckycare.example/problems/domain-rule',
        code: 'domain_rule',
        status: 409,
        detail: 'ไม่สามารถดําเนินการตามกฎของระบบได้',
      },
      { status: 409, statusText: 'Conflict' },
    );

    await nextTick();
    fixture.detectChanges();

    const error = fixture.nativeElement.querySelector(
      '[data-testid="task-action-error"]',
    ) as HTMLElement | null;

    expect(error?.textContent).toContain('ไม่สามารถดําเนินการตามกฎของระบบได้');
    expect(fixture.nativeElement.querySelector('[data-testid="task-conflict"]')).toBeNull();
  });

  it('FE4-T08 a 412 shows the conflict message and reloads the latest task', async () => {
    const fixture = await render(baseTask({ status: 'assigned', version: 4 }));

    click(fixture, '[data-testid="task-action-accept"]');

    const request = httpMock.expectOne((item) => item.url.endsWith('/care-tasks/task-1/accept'));

    expect(request.request.headers.get('If-Match')).toBe('4');

    request.flush(
      {
        type: 'https://api.luckycare.example/problems/version-conflict',
        code: 'version_conflict',
        status: 412,
        detail: 'ข้อมูลถูกแก้ไขโดยผู้อื่น กรุณาโหลดข้อมูลใหม่',
      },
      { status: 412, statusText: 'Precondition Failed' },
    );

    await nextTick();
    fixture.detectChanges();

    const conflict = fixture.nativeElement.querySelector(
      '[data-testid="task-conflict"]',
    ) as HTMLElement | null;

    expect(conflict?.textContent).toContain('ข้อมูลถูกแก้ไขโดยคนอื่น');
    expect(conflict?.textContent).toContain('โหลดข้อมูลใหม่');

    click(fixture, '[data-testid="task-conflict-reload"]');

    const reloaded = httpMock.expectOne((item) => item.url.endsWith('/care-tasks/task-1'));

    reloaded.flush({ data: baseTask({ status: 'assigned', version: 5 }) });

    await nextTick();
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[data-testid="task-conflict"]')).toBeNull();
  });

  it('FE4-T09 a safe card renders without description or evidence', async () => {
    const fixture = await render({
      id: 'task-card',
      type: 'CUSTOM',
      title: 'งานที่เห็นเฉพาะข้อมูลจําเป็น',
      due_at: '2026-10-23T17:00:00+07:00',
      status: 'assigned',
      priority: 'high',
      profile_display_name: 'คุณพ่อ',
      version: 2,
      // A card must drop these even if a broken payload carries them.
      description: 'ห้ามแสดงรายละเอียดนี้',
      evidence_policy: 'value',
    });

    const card = fixture.nativeElement.querySelector(
      '[data-testid="task-safe-card"]',
    ) as HTMLElement | null;

    expect(card).not.toBeNull();
    expect(fixture.nativeElement.textContent).toContain('งานที่เห็นเฉพาะข้อมูลจําเป็น');
    expect(fixture.nativeElement.textContent).not.toContain('ห้ามแสดงรายละเอียดนี้');
    expect(fixture.nativeElement.querySelector('[data-testid="task-description"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="task-evidence"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="task-actions"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="task-detail-tier"]')).toBeNull();
  });

  it('FE4-T10 clinical completion stays disabled until the required evidence exists', async () => {
    const fixture = await render(
      baseTask({
        tier: 'clinical',
        status: 'in_progress',
        evidence_policy: 'value',
        version: 2,
      }),
      [],
    );

    click(fixture, '[data-testid="task-action-complete"]');

    const required = fixture.nativeElement.querySelector(
      '[data-testid="task-evidence-required"]',
    ) as HTMLElement | null;
    const submit = fixture.nativeElement.querySelector(
      '[data-testid="task-complete-submit"]',
    ) as HTMLButtonElement | null;

    expect(required?.textContent).toContain('ต้องแนบค่าที่วัดก่อนปิดงานทางคลินิก');
    expect(submit?.disabled).toBe(true);

    httpMock.expectNone((item) => item.url.endsWith('/care-tasks/task-1/complete'));
  });

  it('FLG-T08 a server photo flag enables completion without the evidence list', async () => {
    const fixture = TestBed.createComponent(TaskDetail);

    fixture.componentRef.setInput('taskId', 'task-1');
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.url.endsWith('/care-tasks/task-1'))
      .flush({
        data: baseTask({
          tier: 'clinical',
          status: 'in_progress',
          evidence_policy: 'photo',
          has_photo_evidence: true,
          version: 2,
        }),
      });

    await nextTick();

    // Reading evidence is a *different* authority (F-06). The flag alone must
    // still unlock completion when the evidence list is not readable.
    httpMock
      .expectOne((item) => item.url.endsWith('/care-tasks/task-1/evidence'))
      .flush(
        {
          type: 'https://api.luckycare.example/problems/forbidden',
          code: 'forbidden',
          status: 403,
          detail: 'ไม่มีสิทธิ์เข้าถึงข้อมูลนี้',
        },
        { status: 403, statusText: 'Forbidden' },
      );

    await nextTick();
    fixture.detectChanges();

    click(fixture, '[data-testid="task-action-complete"]');

    const submit = fixture.nativeElement.querySelector(
      '[data-testid="task-complete-submit"]',
    ) as HTMLButtonElement | null;

    expect(submit?.disabled).toBe(false);
    expect(
      fixture.nativeElement.querySelector('[data-testid="task-evidence-required"]'),
    ).toBeNull();
    httpMock.expectNone((item) => item.url.endsWith('/care-tasks/task-1/complete'));
  });

  it('FE7-T06 the completion button becomes enabled once a photo evidence row exists (policy photo)', async () => {
    const fixture = await render(
      baseTask({
        tier: 'clinical',
        status: 'in_progress',
        evidence_policy: 'photo',
        version: 2,
      }),
      [],
    );

    click(fixture, '[data-testid="task-action-complete"]');

    const before = fixture.nativeElement.querySelector(
      '[data-testid="task-complete-submit"]',
    ) as HTMLButtonElement | null;

    expect(before?.disabled).toBe(true);

    const select = fixture.nativeElement.querySelector(
      '[data-testid="evidence-type"]',
    ) as HTMLSelectElement;

    select.value = 'photo';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const file = new File(['photo-bytes'], 'pressure.jpg', { type: 'image/jpeg' });
    const input = fixture.nativeElement.querySelector(
      '[data-testid="evidence-photo-input"]',
    ) as HTMLInputElement;

    Object.defineProperty(input, 'files', {
      configurable: true,
      value: { 0: file, length: 1, item: () => file },
    });
    input.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const form = fixture.nativeElement.querySelector(
      '[data-testid="evidence-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.url.endsWith('/profiles/profile-1/documents'))
      .flush(
        {
          data: {
            id: 'doc-1',
            kind: 'photo',
            mime_type: 'image/jpeg',
            size_bytes: 11,
            captured_at: null,
            status: 'available',
          },
        },
        { status: 201, statusText: 'Created' },
      );

    await nextTick();
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.url.endsWith('/care-tasks/task-1/evidence'))
      .flush(
        {
          data: {
            id: 'evidence-1',
            type: 'photo',
            metric: null,
            value: null,
            unit: null,
            document_id: 'doc-1',
            note: null,
            created_by: 7,
            created_at: '2026-10-24T09:00:00+07:00',
          },
        },
        { status: 201, statusText: 'Created' },
      );

    await nextTick();
    fixture.detectChanges();

    const after = fixture.nativeElement.querySelector(
      '[data-testid="task-complete-submit"]',
    ) as HTMLButtonElement | null;

    expect(after?.disabled).toBe(false);
    expect(
      fixture.nativeElement.querySelector('[data-testid="task-evidence-required"]'),
    ).toBeNull();
  });
});
