import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { parseDashboardPayload } from './dashboard.models';
import { DashboardService } from './dashboard.service';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const PAYLOAD = {
  family: {
    visible: true,
    members: [
      {
        person_id: 'person-1',
        display_name: 'คุณพ่อ',
        ring: 'inner',
        status: 'yellow',
        reason: 'tasks_pending',
      },
    ],
  },
  alerts: {
    visible: true,
    items: [
      {
        level: 'warning',
        person_id: 'person-1',
        code: 'tasks_overdue',
        task_id: null,
        message_key: 'dashboard.alert.tasks_overdue',
      },
    ],
  },
  tasks_today: {
    visible: true,
    total: 2,
    done: 1,
    overdue: 1,
    items: [
      {
        id: 'task-1',
        type: 'DAILY_CARE',
        title: 'กินยาเช้า',
        due_at: '2026-10-23T01:00:00+00:00',
        status: 'open',
        priority: 'normal',
        profile_display_name: 'คุณพ่อ',
        version: 1,
      },
    ],
  },
  vitals: {
    visible: true,
    cards: [
      {
        type: 'blood_pressure',
        person_id: 'person-1',
        status: 'normal',
        latest: {
          value: 120,
          secondary_value: 80,
          unit: 'mmHg',
          recorded_at: '2026-10-23T02:00:00+00:00',
        },
        trend_7d: { count: 3, min: 110, max: 130, avg: 120 },
      },
    ],
  },
  next_appointment: {
    visible: true,
    id: 'appointment-1',
    person_id: 'person-1',
    title: 'พบแพทย์',
    scheduled_at: '2026-11-01T02:00:00+00:00',
    status: 'planned',
  },
  generated_at: '2026-10-23T02:00:00+00:00',
  cache_ttl: 30,
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

describe('DashboardService', () => {
  let service: DashboardService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    service = TestBed.inject(DashboardService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE2-T01 maps the payload into the five typed zones', async () => {
    const loading = service.load();

    const request = httpMock.expectOne('/api/v1/workspaces/ws-1/dashboard');
    expect(request.request.method).toBe('GET');
    request.flush({ data: PAYLOAD });

    await loading;

    const data = service.dashboard();

    expect(data).not.toBeNull();
    expect(data?.family.visible).toBe(true);
    expect(data?.family.members[0].display_name).toBe('คุณพ่อ');
    expect(data?.alerts.items[0].message_key).toBe('dashboard.alert.tasks_overdue');
    expect(data?.tasks_today).toMatchObject({ total: 2, done: 1, overdue: 1 });
    expect(data?.tasks_today.items[0].title).toBe('กินยาเช้า');
    expect(data?.vitals.cards[0].latest).toMatchObject({
      value: 120,
      secondary_value: 80,
      unit: 'mmHg',
    });
    expect(data?.vitals.cards[0].trend_7d).toMatchObject({
      count: 3,
      min: 110,
      max: 130,
      avg: 120,
    });
    expect(data?.next_appointment.title).toBe('พบแพทย์');
    expect(data?.generated_at).toBe('2026-10-23T02:00:00+00:00');
    expect(data?.cache_ttl).toBe(30);
  });

  it('FE2-T03 treats an absent zone as hidden and never throws', async () => {
    const loading = service.load();

    httpMock.expectOne('/api/v1/workspaces/ws-1/dashboard').flush({
      data: {
        family: { visible: true, members: [] },
        alerts: { visible: false, reason: 'out_of_scope' },
        tasks_today: { visible: false, reason: 'out_of_scope' },
        next_appointment: { visible: false, reason: 'out_of_scope' },
        generated_at: '2026-10-23T02:00:00+00:00',
        cache_ttl: 30,
      },
    });

    await loading;

    expect(service.dashboard()?.vitals.visible).toBe(false);
    expect(service.dashboard()?.vitals.cards).toEqual([]);
    expect(() => parseDashboardPayload(null)).not.toThrow();
    expect(() => parseDashboardPayload('not-an-object')).not.toThrow();
    expect(parseDashboardPayload(undefined).family.visible).toBe(false);
  });

  it('FE2-T05 refresh bypasses the cache with bypass_cache=1', async () => {
    const loading = service.load();
    httpMock.expectOne('/api/v1/workspaces/ws-1/dashboard').flush({ data: PAYLOAD });
    await loading;

    const refreshing = service.refresh();

    const request = httpMock.expectOne('/api/v1/workspaces/ws-1/dashboard?bypass_cache=1');
    expect(request.request.urlWithParams).toContain('bypass_cache=1');
    request.flush({ data: PAYLOAD });

    await refreshing;

    expect(service.dashboard()?.tasks_today.total).toBe(2);
  });

  it('sends profile_id when a person is selected', async () => {
    const loading = service.load('person-1');

    const request = httpMock.expectOne('/api/v1/workspaces/ws-1/dashboard?profile_id=person-1');
    request.flush({ data: PAYLOAD });

    await loading;

    expect(service.profileId()).toBe('person-1');
  });
});
