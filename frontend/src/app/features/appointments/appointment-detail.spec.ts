import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { MembershipSummary } from '../../core/auth/auth.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { AppointmentDetail } from './appointment-detail';

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

function appointmentPayload(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: 'appointment-1',
    profile_id: 'profile-1',
    profile_display_name: 'คุณพ่อ',
    title: 'ตรวจติดตามโรคความดัน',
    provider_name: 'นพ.สมชาย',
    facility: 'โรงพยาบาลกรุงเทพ',
    purpose: 'ติดตามความดันโลหิต',
    location: 'อาคาร A ชั้น 3',
    scheduled_at: '2026-11-05T09:00:00+07:00',
    duration_minutes: 30,
    status: 'planned',
    cancelled_reason: null,
    rescheduled_from_id: null,
    transported_by_task_id: null,
    prepared_documents: [
      { label: 'บัตรประชาชน', done: false },
      { label: 'สมุดสุขภาพ', done: true },
    ],
    follow_up_task_id: null,
    version: 1,
    source: 'manual',
    created_by: 1,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('AppointmentDetail', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [AppointmentDetail],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  async function render(
    status: string,
    overrides: Record<string, unknown> = {},
  ): Promise<ComponentFixture<AppointmentDetail>> {
    const fixture = TestBed.createComponent(AppointmentDetail);

    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.componentRef.setInput('appointmentId', 'appointment-1');
    fixture.detectChanges();

    httpMock
      .expectOne(
        (item) => item.url.endsWith('/appointments/appointment-1') && item.method === 'GET',
      )
      .flush({ data: appointmentPayload({ status, ...overrides }) });

    await nextTick();
    fixture.detectChanges();

    return fixture;
  }

  function query(
    fixture: ComponentFixture<AppointmentDetail>,
    selector: string,
  ): HTMLElement | null {
    return fixture.nativeElement.querySelector(selector) as HTMLElement | null;
  }

  function queryAll(
    fixture: ComponentFixture<AppointmentDetail>,
    selector: string,
  ): readonly HTMLElement[] {
    return Array.from(fixture.nativeElement.querySelectorAll(selector) as NodeListOf<HTMLElement>);
  }

  function click(fixture: ComponentFixture<AppointmentDetail>, selector: string): void {
    query(fixture, selector)?.click();
    fixture.detectChanges();
  }

  it('FE6-T05 cancel is blocked until a reason of at least 5 characters is entered', async () => {
    const fixture = await render('planned', { version: 2 });

    click(fixture, '[data-testid="appointment-action-cancel"]');

    const panel = query(fixture, '[data-testid="appointment-cancel-panel"]');
    const form = panel?.querySelector('form') as HTMLFormElement | null;

    expect(panel).not.toBeNull();
    expect(form).not.toBeNull();

    form?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await nextTick();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
    httpMock.expectNone((item) => item.url.endsWith('/appointments/appointment-1/cancel'));

    const reason = query(
      fixture,
      '[data-testid="appointment-cancel-reason"]',
    ) as HTMLTextAreaElement;

    reason.value = 'แพทย์เลื่อนนัด';
    reason.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    form?.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = httpMock.expectOne((item) =>
      item.url.endsWith('/appointments/appointment-1/cancel'),
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('If-Match')).toBe('2');
    expect(request.request.body).toEqual({ reason: 'แพทย์เลื่อนนัด' });

    request.flush({
      data: appointmentPayload({
        version: 3,
        status: 'cancelled',
        cancelled_reason: 'แพทย์เลื่อนนัด',
      }),
    });

    await nextTick();
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="appointment-cancel-panel"]')).toBeNull();
    expect(query(fixture, '[data-testid="appointment-status"]')?.textContent).toContain(
      'ยกเลิกแล้ว',
    );
  });

  it('FE6-T06 offers the actions the state machine allows per status', async () => {
    const planned = await render('planned');

    expect(query(planned, '[data-testid="appointment-action-confirm"]')).not.toBeNull();
    expect(query(planned, '[data-testid="appointment-action-reschedule"]')).not.toBeNull();
    expect(query(planned, '[data-testid="appointment-action-cancel"]')).not.toBeNull();
    expect(query(planned, '[data-testid="appointment-action-attended"]')).toBeNull();

    planned.destroy();

    const confirmed = await render('confirmed');

    expect(query(confirmed, '[data-testid="appointment-action-attended"]')).not.toBeNull();
    expect(query(confirmed, '[data-testid="appointment-action-reschedule"]')).not.toBeNull();
    expect(query(confirmed, '[data-testid="appointment-action-cancel"]')).not.toBeNull();
    expect(query(confirmed, '[data-testid="appointment-action-confirm"]')).toBeNull();

    confirmed.destroy();

    const attended = await render('attended', { follow_up_task_id: 'task-9' });

    expect(query(attended, '[data-testid="appointment-actions"]')).toBeNull();
    expect(query(attended, '[data-testid="appointment-follow-up"]')).not.toBeNull();

    attended.destroy();
  });

  it('FE6-T07a a 409 renders the server Thai domain sentence', async () => {
    const fixture = await render('planned', { version: 2 });

    click(fixture, '[data-testid="appointment-action-confirm"]');

    const request = httpMock.expectOne(
      (item) => item.url.endsWith('/appointments/appointment-1') && item.method === 'PATCH',
    );

    expect(request.request.headers.get('If-Match')).toBe('2');
    expect(request.request.body).toEqual({ status: 'confirmed' });

    request.flush(
      {
        type: 'https://api.luckycare.example/problems/domain-rule',
        code: 'domain_rule',
        status: 409,
        detail: 'ไม่สามารถเปลี่ยนสถานะนัดหมายตามกฎของระบบได้',
      },
      { status: 409, statusText: 'Conflict' },
    );

    await nextTick();
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="appointment-action-error"]')?.textContent).toContain(
      'ไม่สามารถเปลี่ยนสถานะนัดหมายตามกฎของระบบได้',
    );
    expect(query(fixture, '[data-testid="appointment-conflict"]')).toBeNull();
  });

  it('FE6-T07b a 412 shows the conflict action and reloads the latest row', async () => {
    const fixture = await render('planned', { version: 3 });

    click(fixture, '[data-testid="appointment-action-confirm"]');

    const request = httpMock.expectOne(
      (item) => item.url.endsWith('/appointments/appointment-1') && item.method === 'PATCH',
    );

    expect(request.request.headers.get('If-Match')).toBe('3');

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

    const conflict = query(fixture, '[data-testid="appointment-conflict"]');

    expect(conflict?.textContent).toContain('ข้อมูลถูกแก้ไขโดยคนอื่น');
    expect(conflict?.textContent).toContain('โหลดข้อมูลใหม่');

    click(fixture, '[data-testid="appointment-conflict-reload"]');

    httpMock
      .expectOne(
        (item) => item.url.endsWith('/appointments/appointment-1') && item.method === 'GET',
      )
      .flush({ data: appointmentPayload({ status: 'planned', version: 4 }) });

    await nextTick();
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="appointment-conflict"]')).toBeNull();
  });

  it('FE6-T08 after attended the follow-up task link is shown once, not twice', async () => {
    const fixture = await render('confirmed', { version: 2 });

    const attended = query(
      fixture,
      '[data-testid="appointment-action-attended"]',
    ) as HTMLButtonElement;

    attended.click();
    // A second click before the response must not start a second create-task
    // request or a second spinner.
    attended.click();
    fixture.detectChanges();

    const requests = httpMock.match((item) =>
      item.url.endsWith('/appointments/appointment-1/attended'),
    );

    expect(requests.length).toBe(1);
    expect(requests[0].request.method).toBe('POST');
    expect(requests[0].request.headers.get('If-Match')).toBe('2');
    expect(requests[0].request.body).toEqual({});

    requests[0].flush({
      data: appointmentPayload({
        status: 'attended',
        version: 3,
        follow_up_task_id: 'task-9',
      }),
    });

    await nextTick();
    fixture.detectChanges();

    const followUps = queryAll(fixture, '[data-testid="appointment-follow-up"]');

    expect(followUps.length).toBe(1);
    expect(followUps[0].textContent).toContain('สร้างงานติดตามแล้ว');
    expect(query(fixture, '[data-testid="appointment-action-attended"]')).toBeNull();
    expect(queryAll(fixture, '.spinner').length).toBe(0);
  });
});
