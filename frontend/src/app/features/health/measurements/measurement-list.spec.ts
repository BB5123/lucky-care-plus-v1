import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../../core/auth/auth.models';
import { WorkspaceService } from '../../../core/workspace/workspace.service';
import { MeasurementList } from './measurement-list';

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

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function measurement(
  id: string,
  type: string,
  value: number,
  secondary: number | null,
  unit: string,
  recordedAt: string,
): unknown {
  return {
    id,
    type,
    value,
    secondary_value: secondary,
    unit,
    context: null,
    note: null,
    source: 'manual',
    recorded_at: recordedAt,
    recorded_by: 'user-1',
    version: 1,
    created_at: recordedAt,
    updated_at: recordedAt,
  };
}

describe('MeasurementList', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [MeasurementList],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE3-T05 groups by type newest-first and shows 7-day min/max/avg', async () => {
    const now = Date.now();
    const fixture: ComponentFixture<MeasurementList> = TestBed.createComponent(MeasurementList);

    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.detectChanges();

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/measurements'),
    );

    request.flush({
      data: [
        measurement(
          'bp-old',
          'blood_pressure',
          120,
          80,
          'mmHg',
          new Date(now - 2 * 24 * 60 * 60 * 1000).toISOString(),
        ),
        measurement(
          'hr-new',
          'heart_rate',
          72,
          null,
          'bpm',
          new Date(now - 30 * 60 * 1000).toISOString(),
        ),
        measurement(
          'bp-new',
          'blood_pressure',
          130,
          85,
          'mmHg',
          new Date(now - 60 * 60 * 1000).toISOString(),
        ),
      ],
      meta: { next_cursor: null, limit: 50 },
    });

    await nextTick();
    fixture.detectChanges();

    const groups = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="measurement-group"]'),
    ) as HTMLElement[];

    expect(groups.map((group) => group.getAttribute('data-type'))).toEqual([
      'heart_rate',
      'blood_pressure',
    ]);

    const bloodPressure = groups[1];
    const stats = bloodPressure.querySelector('[data-testid="stats-7d"]') as HTMLElement;

    expect(stats.textContent).toContain('ต่ําสุด 120');
    expect(stats.textContent).toContain('สูงสุด 130');
    expect(stats.textContent).toContain('เฉลี่ย 125');
    expect(stats.textContent).toContain('(2 ครั้ง)');

    const rows = Array.from(
      bloodPressure.querySelectorAll('[data-testid="measurement-row"]'),
    ) as HTMLElement[];

    expect(rows[0].textContent).toContain('130/85');
    expect(rows[0].textContent).toContain('mmHg');
    expect(rows[1].textContent).toContain('120/80');
    expect(rows[1].textContent).toContain('mmHg');

    expect(groups[0].textContent).toContain('72');
    expect(groups[0].textContent).toContain('bpm');
  });
});
