import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { HealthRecordService } from './health-record.service';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const MEASUREMENT_PAYLOAD = {
  data: [
    {
      id: 'measurement-1',
      type: 'blood_pressure',
      value: 120,
      secondary_value: 80,
      unit: 'mmHg',
      context: null,
      note: 'วัดที่บ้าน',
      source: 'manual',
      recorded_at: '2026-10-23T02:00:00+00:00',
      recorded_by: 'user-1',
      version: 7,
      created_at: '2026-10-23T02:00:00+00:00',
      updated_at: '2026-10-23T02:05:00+00:00',
    },
  ],
  meta: { next_cursor: 'cursor-2', limit: 20 },
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

describe('HealthRecordService', () => {
  let service: HealthRecordService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    service = TestBed.inject(HealthRecordService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE3-T01 maps a measurement payload and preserves version', async () => {
    const loading = service.listMeasurements('profile-1', { limit: 20 });

    const request = httpMock.expectOne((item) =>
      item.urlWithParams.startsWith('/api/v1/workspaces/ws-1/profiles/profile-1/measurements'),
    );

    expect(request.request.method).toBe('GET');
    request.flush(MEASUREMENT_PAYLOAD);

    await loading;

    const measurement = service.measurements()[0];

    expect(measurement).toBeDefined();
    expect(measurement?.id).toBe('measurement-1');
    expect(measurement?.type).toBe('blood_pressure');
    expect(measurement?.value).toBe(120);
    expect(measurement?.secondary_value).toBe(80);
    expect(measurement?.unit).toBe('mmHg');
    expect(measurement?.note).toBe('วัดที่บ้าน');
    expect(measurement?.version).toBe(7);
    expect(service.measurementsNextCursor()).toBe('cursor-2');
  });

  it('returns no rows for a malformed payload instead of throwing', async () => {
    const loading = service.listMeasurements('profile-1');

    httpMock
      .expectOne((item) => item.url.includes('/profiles/profile-1/measurements'))
      .flush({ data: [{ id: 'broken', type: 'unknown_type', value: 'nope' }] });

    await loading;

    expect(service.measurements()).toEqual([]);
  });
});
