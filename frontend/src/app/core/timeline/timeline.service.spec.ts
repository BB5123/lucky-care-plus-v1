import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { TimelineService } from './timeline.service';

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

describe('TimelineService', () => {
  let service: TimelineService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    service = TestBed.inject(TimelineService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE5-T01 keeps a redacted entry without id or metadata', async () => {
    const loading = service.load('profile-1', {
      from: '2026-10-01',
      to: '2026-10-24',
      types: ['measurement', 'condition'],
    });

    const request = httpMock.expectOne((item) =>
      item.urlWithParams.startsWith('/api/v1/workspaces/ws-1/profiles/profile-1/timeline'),
    );

    expect(request.request.method).toBe('GET');

    const url = new URL(request.request.url, 'http://localhost');

    expect(url.searchParams.get('from')).toBe('2026-10-01');
    expect(url.searchParams.get('to')).toBe('2026-10-24');
    expect(url.searchParams.get('types')).toBe('measurement,condition');

    request.flush({
      data: [
        {
          id: 'entry-1',
          type: 'measurement',
          occurred_at: '2026-10-24T08:00:00+00:00',
          summary_key: 'timeline.measurement.recorded',
          ref: { type: 'health_measurement', id: 'measurement-1' },
          metadata: { type: 'blood_pressure' },
          redacted: false,
        },
        {
          id: 'leaked-id',
          type: 'condition',
          occurred_at: '2026-10-23T08:00:00+00:00',
          summary_key: 'timeline.condition.recorded',
          metadata: { value: '120/80' },
          redacted: true,
        },
      ],
      meta: { next_cursor: 'cursor-2', limit: 20 },
    });

    await loading;

    const [visible, redacted] = service.items();

    expect(visible.id).toBe('entry-1');
    expect(visible.summary_key).toBe('timeline.measurement.recorded');
    expect(visible.ref).toEqual({ type: 'health_measurement', id: 'measurement-1' });
    expect(visible.metadata).toEqual({ type: 'blood_pressure' });

    expect(redacted.redacted).toBe(true);
    expect(redacted.id).toBeNull();
    expect(redacted.summary_key).toBeNull();
    expect(redacted.ref).toBeNull();
    expect(redacted.metadata).toEqual({});
    expect(redacted.type).toBe('condition');
    expect(redacted.occurred_at).toBe('2026-10-23T08:00:00+00:00');

    expect(service.nextCursor()).toBe('cursor-2');
  });
});
