import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { MembershipSummary } from '../../core/auth/auth.models';
import { HealthProfileSummary, selectCurrentProfile } from '../../core/profiles/profiles.models';
import { ProfilesService } from '../../core/profiles/profiles.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { TimelinePage } from './timeline.page';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const PROFILES: readonly HealthProfileSummary[] = [
  {
    id: 'profile-1',
    person_id: 'person-1',
    display_name: 'คุณพ่อ',
    relationship: 'self',
    kind: 'self',
  },
  {
    id: 'profile-2',
    person_id: 'person-2',
    display_name: 'คุณแม่',
    relationship: 'dependent',
    kind: 'dependent',
  },
];

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return {
    currentWorkspace: current.asReadonly(),
    hasWorkspace: computed(() => current() !== null),
    load: () => Promise.resolve(),
  };
}

function profilesStub(): Partial<ProfilesService> {
  const profiles = signal<readonly HealthProfileSummary[]>(PROFILES);

  return {
    profiles: profiles.asReadonly(),
    currentProfile: computed(() => selectCurrentProfile(profiles())),
    load: () => Promise.resolve(),
    reset: () => undefined,
  };
}

function visibleEntry(
  id: string,
  summaryKey = 'timeline.measurement.recorded',
  occurredAt = '2026-10-24T08:00:00+00:00',
): Record<string, unknown> {
  return {
    id,
    type: 'measurement',
    occurred_at: occurredAt,
    summary_key: summaryKey,
    ref: { type: 'health_measurement', id: `source-${id}` },
    metadata: { type: 'blood_pressure' },
    redacted: false,
  };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function payload(
  items: readonly unknown[],
  nextCursor: string | null = null,
): Record<string, unknown> {
  return { data: items, meta: { next_cursor: nextCursor, limit: 20 } };
}

async function render(): Promise<ComponentFixture<TimelinePage>> {
  const fixture = TestBed.createComponent(TimelinePage);

  fixture.detectChanges();
  await nextTick();

  return fixture;
}

function timelineRequest(httpMock: HttpTestingController) {
  return httpMock.expectOne((item) => item.url.includes('/profiles/profile-1/timeline'));
}

function rowIds(fixture: ComponentFixture<TimelinePage>): readonly string[] {
  return (
    Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="timeline-entry"]'),
    ) as HTMLElement[]
  ).map((row) => row.getAttribute('data-entry-id') ?? '');
}

describe('TimelinePage', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [TimelinePage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: WorkspaceService, useValue: workspaceStub() },
        { provide: ProfilesService, useValue: profilesStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE5-T02 renders a redacted row with no link and no leaked id', async () => {
    const fixture = await render();

    timelineRequest(httpMock).flush(
      payload([
        {
          id: 'leaked-id',
          type: 'condition',
          occurred_at: '2026-10-23T08:00:00+00:00',
          summary_key: 'timeline.condition.recorded',
          metadata: { value: '120/80' },
          redacted: true,
        },
        visibleEntry('entry-1'),
      ]),
    );

    await nextTick();
    fixture.detectChanges();

    const redacted = fixture.nativeElement.querySelector(
      '[data-testid="timeline-redacted"]',
    ) as HTMLElement | null;

    expect(redacted).not.toBeNull();
    expect(redacted?.textContent).toContain('มีเหตุการณ์ที่ไม่สามารถแสดงรายละเอียดได้');
    expect(redacted?.querySelector('a')).toBeNull();
    expect(redacted?.getAttribute('data-entry-id')).toBeNull();
    expect(redacted?.getAttribute('id')).toBeNull();
    expect(redacted?.querySelector('[id]')).toBeNull();
    expect(redacted?.outerHTML).not.toContain('leaked-id');
    expect(redacted?.outerHTML).not.toContain('120/80');
    expect(redacted?.outerHTML).not.toContain('timeline.condition.recorded');
  });

  it('FE5-T03 type filter and date range change the request query', async () => {
    const fixture = await render();

    timelineRequest(httpMock).flush(payload([]));

    await nextTick();
    fixture.detectChanges();

    const medicationChip = fixture.nativeElement.querySelector(
      '[data-type-filter="medication"]',
    ) as HTMLButtonElement | null;

    expect(medicationChip).not.toBeNull();
    medicationChip?.click();

    await nextTick();

    const typeRequest = timelineRequest(httpMock);
    const typeUrl = new URL(typeRequest.request.urlWithParams, 'http://localhost');

    expect(typeUrl.searchParams.get('types')).toBe('medication_plan,medication_record');
    typeRequest.flush(payload([]));

    await nextTick();
    fixture.detectChanges();

    const sevenDays = fixture.nativeElement.querySelector(
      '[data-range-days="7"]',
    ) as HTMLButtonElement | null;

    expect(sevenDays).not.toBeNull();
    sevenDays?.click();

    await nextTick();

    const rangeRequest = timelineRequest(httpMock);
    const rangeUrl = new URL(rangeRequest.request.urlWithParams, 'http://localhost');
    const from = rangeUrl.searchParams.get('from');
    const to = rangeUrl.searchParams.get('to');

    expect(from).not.toBeNull();
    expect(to).not.toBeNull();
    expect((Date.parse(to ?? '') - Date.parse(from ?? '')) / (24 * 60 * 60 * 1000)).toBe(7);
    expect(rangeUrl.searchParams.get('types')).toBe('medication_plan,medication_record');

    rangeRequest.flush(payload([]));

    await nextTick();
    fixture.detectChanges();
  });

  it('FE5-T04 load more appends by cursor without duplicating rows', async () => {
    const fixture = await render();

    timelineRequest(httpMock).flush(
      payload([visibleEntry('entry-1'), visibleEntry('entry-2')], 'cursor-2'),
    );

    await nextTick();
    fixture.detectChanges();

    expect(rowIds(fixture)).toEqual(['entry-1', 'entry-2']);

    const loadMore = fixture.nativeElement.querySelector(
      '[data-testid="timeline-load-more"]',
    ) as HTMLButtonElement | null;

    expect(loadMore).not.toBeNull();
    loadMore?.click();

    await nextTick();

    const pageRequest = timelineRequest(httpMock);
    const url = new URL(pageRequest.request.urlWithParams, 'http://localhost');

    expect(url.searchParams.get('cursor')).toBe('cursor-2');

    pageRequest.flush(payload([visibleEntry('entry-2'), visibleEntry('entry-3')]));

    await nextTick();
    fixture.detectChanges();

    expect(rowIds(fixture)).toEqual(['entry-1', 'entry-2', 'entry-3']);
  });

  it('FE5-T05 an unknown summary key falls back to the generic Thai sentence', async () => {
    const fixture = await render();

    timelineRequest(httpMock).flush(payload([visibleEntry('entry-1', 'timeline.unknown.event')]));

    await nextTick();
    fixture.detectChanges();

    const entry = fixture.nativeElement.querySelector(
      '[data-testid="timeline-entry"]',
    ) as HTMLElement | null;

    expect(entry).not.toBeNull();
    expect(entry?.textContent).toContain('มีเหตุการณ์ใหม่ในไทม์ไลน์');
    expect(entry?.textContent).not.toContain('timeline.unknown.event');
  });
});
