import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { buildAppointmentBody, parseAppointment } from './appointment.models';
import { AppointmentService } from './appointment.service';

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
      { label: 'สมุดสุขภาพ', done: false },
      { label: 'ผลตรวจเดิม', done: true },
    ],
    follow_up_task_id: null,
    version: 4,
    source: 'manual',
    created_by: 1,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const DRAFT = {
  title: 'ตรวจติดตามโรคความดัน',
  provider_name: 'นพ.สมชาย',
  facility: '',
  purpose: '',
  location: '',
  scheduled_at: '2026-11-05T09:00',
  duration_minutes: '',
  prepared_documents: [{ label: 'บัตรประชาชน', done: false }],
};

describe('AppointmentService', () => {
  let service: AppointmentService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    service = TestBed.inject(AppointmentService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE6-T01 maps an appointment and keeps version and checklist order', async () => {
    const loading = service.loadDetail('profile-1', 'appointment-1');

    const request = httpMock.expectOne((item) => item.url.endsWith('/appointments/appointment-1'));

    expect(request.request.method).toBe('GET');

    request.flush({ data: appointmentPayload() });
    await loading;

    const detail = service.detail();

    expect(detail).not.toBeNull();
    expect(detail?.id).toBe('appointment-1');
    expect(detail?.version).toBe(4);
    expect(detail?.status).toBe('planned');
    expect(detail?.follow_up_task_id).toBeNull();
    expect(detail?.prepared_documents.map((document) => document.label)).toEqual([
      'บัตรประชาชน',
      'สมุดสุขภาพ',
      'ผลตรวจเดิม',
    ]);
    expect(detail?.prepared_documents.map((document) => document.done)).toEqual([
      false,
      false,
      true,
    ]);
  });

  it('FE6-T03 create sends the contract fields and no empty optional strings', async () => {
    const body = buildAppointmentBody(DRAFT);
    const loading = service.create('profile-1', body);

    const request = httpMock.expectOne(
      (item) => item.url.endsWith('/appointments') && item.method === 'POST',
    );

    expect(request.request.body).toEqual({
      title: 'ตรวจติดตามโรคความดัน',
      provider_name: 'นพ.สมชาย',
      scheduled_at: new Date('2026-11-05T09:00').toISOString(),
      prepared_documents: [{ label: 'บัตรประชาชน', done: false }],
    });
    expect(request.request.body['facility']).toBeUndefined();
    expect(request.request.body['purpose']).toBeUndefined();
    expect(request.request.body['location']).toBeUndefined();
    expect(request.request.body['duration_minutes']).toBeUndefined();
    expect(request.request.headers.has('If-Match')).toBe(false);

    request.flush({ data: appointmentPayload({ scheduled_at: body.scheduled_at }) });
    await loading;

    expect(service.appointments()[0].id).toBe('appointment-1');
  });

  it('FE6-T04 toggling a checklist item sends If-Match and only the changed index', async () => {
    const source = parseAppointment(appointmentPayload());

    if (source === null) {
      throw new Error('test fixture is not an appointment');
    }

    const loading = service.toggleDocument('profile-1', source, 1, true);

    const request = httpMock.expectOne((item) =>
      item.url.endsWith('/appointments/appointment-1/documents'),
    );

    expect(request.request.method).toBe('PATCH');
    expect(request.request.headers.get('If-Match')).toBe('4');
    expect(request.request.body).toEqual({ index: 1, done: true });

    request.flush({
      data: appointmentPayload({
        version: 5,
        prepared_documents: [
          { label: 'บัตรประชาชน', done: false },
          { label: 'สมุดสุขภาพ', done: true },
          { label: 'ผลตรวจเดิม', done: true },
        ],
      }),
    });
    await loading;

    expect(service.detail()?.version).toBe(5);
    expect(service.detail()?.prepared_documents[1].done).toBe(true);
  });

  it('FE6-T09 reschedule POSTs to the collection with rescheduled_from_id, not a PATCH', async () => {
    const source = parseAppointment(appointmentPayload({ version: 2 }));

    if (source === null) {
      throw new Error('test fixture is not an appointment');
    }

    const loading = service.reschedule('profile-1', source, buildAppointmentBody(DRAFT));

    const request = httpMock.expectOne(
      (item) => item.url.endsWith('/appointments') && item.method === 'POST',
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.headers.has('If-Match')).toBe(false);
    expect(request.request.body['rescheduled_from_id']).toBe('appointment-1');
    expect(request.request.body['title']).toBe('ตรวจติดตามโรคความดัน');

    request.flush({
      data: appointmentPayload({
        id: 'appointment-2',
        version: 1,
        rescheduled_from_id: 'appointment-1',
      }),
    });
    await loading;

    expect(service.appointments()[0].id).toBe('appointment-2');
    expect(service.detail()?.rescheduled_from_id).toBe('appointment-1');
  });
});
