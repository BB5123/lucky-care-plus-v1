import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { ReportService } from './report.service';

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

const REPORT = {
  id: 'report-1',
  kind: 'doctor_brief',
  period_from: '2026-09-24',
  period_to: '2026-10-24',
  created_at: '2026-10-24T16:00:00+00:00',
  payload: {
    sections: ['conditions', 'allergies', 'medications', 'vitals', 'appointments', 'care_tasks'],
    profile: { name: 'สมชาย', birth_year: 1985 },
    conditions: [{ name: 'เบาหวาน', diagnosed_year: 2018 }],
    allergies: [{ substance: 'Penicillin', severity: 'severe' }],
    medications: [{ name: 'Metformin', schedule: ['08:00', '20:00'] }],
    vitals: [{ type: 'blood_pressure', count: 2 }],
    appointments: [{ status: 'attended' }],
    care_tasks: { total_open: 2 },
  },
};

describe('ReportService', () => {
  let httpMock: HttpTestingController;
  let service: ReportService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    service = TestBed.inject(ReportService);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('BRF-UI-T01 creates a brief for the profile and parses the frozen payload', async () => {
    const pending = service.create('profile-1');

    const request = httpMock.expectOne(
      (item) =>
        item.method === 'POST' &&
        item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/health-reports',
    );

    expect(request.request.body).toEqual({});
    request.flush({ data: REPORT });

    const report = await pending;

    expect(report.id).toBe('report-1');
    expect(report.payload?.profile?.name).toBe('สมชาย');
    expect(report.payload?.conditions.length).toBe(1);
    expect(report.payload?.careTasksOpen).toBe(2);
  });

  it('BRF-UI-T02 shares with a bounded lifetime and returns the capability link', async () => {
    const pending = service.share('profile-1', 'report-1');

    const request = httpMock.expectOne(
      (item) =>
        item.method === 'POST' &&
        item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/health-reports/report-1/share',
    );

    expect(request.request.body).toEqual({ expires_in_minutes: 30 });

    request.flush({
      data: {
        id: 'share-1',
        url: 'https://app.example/briefs/token-1',
        expires_at: '2026-10-24T16:30:00+00:00',
      },
    });

    const share = await pending;

    expect(share.id).toBe('share-1');
    expect(share.url).toBe('https://app.example/briefs/token-1');
    expect(share.expiresAt).toBe('2026-10-24T16:30:00+00:00');
  });

  it('BRF-UI-T03 rejects a malformed create response', async () => {
    const pending = service.create('profile-1');

    httpMock
      .expectOne(
        (item) =>
          item.method === 'POST' &&
          item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/health-reports',
      )
      .flush({ data: { kind: 'doctor_brief' } });

    await expect(pending).rejects.toThrow('malformed');
  });
});
