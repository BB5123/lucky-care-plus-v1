import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { MembershipSummary } from '../../core/auth/auth.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { TaskList } from './task-list';

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

function fullTask(
  id: string,
  title: string,
  dueAt: string,
  status: string,
): Record<string, unknown> {
  return {
    id,
    profile_id: 'profile-1',
    profile_display_name: 'คุณพ่อ',
    tier: 'delegated',
    type: 'MEDICATION_SUPPORT',
    title,
    description: 'รายละเอียดงาน',
    priority: 'normal',
    scheduled_for: dueAt,
    due_at: dueAt,
    timezone: 'Asia/Bangkok',
    status,
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
    created_at: dueAt,
    updated_at: dueAt,
  };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('TaskList', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    // Only `Date` is faked so the overdue/today/later grouping is stable;
    // real timers keep the async HTTP helpers working.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-23T10:00:00'));

    TestBed.configureTestingModule({
      imports: [TaskList],
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
    vi.useRealTimers();
    TestBed.resetTestingModule();
  });

  it('FE4-T05 groups overdue, today and later, and filters by assignee=me', async () => {
    const fixture: ComponentFixture<TaskList> = TestBed.createComponent(TaskList);

    fixture.componentRef.setInput('workspaceId', 'ws-1');
    fixture.detectChanges();

    const request = httpMock.expectOne((item) => item.url.includes('/workspaces/ws-1/care-tasks'));

    expect(request.request.method).toBe('GET');

    request.flush({
      data: [
        fullTask('task-overdue', 'งานที่เลยกําหนด', '2026-10-22T09:00:00', 'in_progress'),
        fullTask('task-today', 'งานของวันนี้', '2026-10-23T15:00:00', 'assigned'),
        fullTask('task-later', 'งานถัดไป', '2026-10-25T09:00:00', 'open'),
      ],
      meta: { next_cursor: null, limit: 20 },
    });

    await nextTick();
    fixture.detectChanges();

    const groups = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="task-group"]'),
    ) as HTMLElement[];

    expect(groups.map((group) => group.getAttribute('data-group'))).toEqual([
      'overdue',
      'today',
      'later',
    ]);

    expect(groups[0].textContent).toContain('งานที่เลยกําหนด');
    expect(groups[1].textContent).toContain('งานของวันนี้');
    expect(groups[2].textContent).toContain('งานถัดไป');

    const mine = fixture.nativeElement.querySelector(
      '[data-testid="task-filter-mine"]',
    ) as HTMLInputElement;

    mine.click();
    fixture.detectChanges();

    expect(mine.checked).toBe(true);

    const filtered = httpMock.expectOne((item) => item.url.includes('/workspaces/ws-1/care-tasks'));

    const url = new URL(filtered.request.url, 'http://localhost');

    expect(url.searchParams.get('assignee')).toBe('me');
    expect(url.searchParams.get('limit')).toBe('20');

    filtered.flush({ data: [], meta: { next_cursor: null, limit: 20 } });

    await nextTick();
    fixture.detectChanges();
  });
});
