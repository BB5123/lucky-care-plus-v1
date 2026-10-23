import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { MembershipSummary } from '../../core/auth/auth.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { DashboardPage } from './dashboard.page';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'สมชาย ใจดี',
  role: 'profile_owner',
  status: 'active',
};

const PAYLOAD = {
  family: {
    visible: true,
    members: [
      {
        person_id: 'person-1',
        profile_id: 'profile-1',
        display_name: 'คุณพ่อ',
        ring: 'inner',
        status: 'green',
        reason: 'stable',
      },
      {
        person_id: 'person-2',
        profile_id: 'profile-2',
        display_name: 'คุณแม่',
        ring: 'support',
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
    overdue: 0,
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
    title: 'ตรวจติดตามอาการ',
    scheduled_at: '2026-11-01T02:00:00+00:00',
    status: 'planned',
  },
  generated_at: '2026-10-23T02:00:00+00:00',
  cache_ttl: 30,
};

function workspaceStub(current: MembershipSummary | null = WORKSPACE): Partial<WorkspaceService> {
  const currentState = signal<MembershipSummary | null>(current);
  const list = signal<readonly MembershipSummary[]>(current === null ? [] : [current]);

  return {
    workspaces: list.asReadonly(),
    currentWorkspace: currentState.asReadonly(),
    hasWorkspace: computed(() => currentState() !== null),
    load: () => Promise.resolve(),
    select: () => true,
    reset: () => undefined,
  };
}

function payloadWith(overrides: Readonly<Record<string, unknown>>): unknown {
  return { ...PAYLOAD, ...overrides };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function installLocalStorage(): void {
  const store = new Map<string, string>();
  const storage: Storage = {
    get length(): number {
      return store.size;
    },
    clear: () => store.clear(),
    getItem: (key: string) => store.get(key) ?? null,
    key: (index: number) => Array.from(store.keys())[index] ?? null,
    removeItem: (key: string) => {
      store.delete(key);
    },
    setItem: (key: string, value: string) => {
      store.set(key, String(value));
    },
  };

  Object.defineProperty(window, 'localStorage', { value: storage, configurable: true });
}

describe('DashboardPage', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    installLocalStorage();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      imports: [DashboardPage],
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
    window.localStorage.clear();
    sessionStorage.clear();
    TestBed.resetTestingModule();
  });

  async function render(payload: unknown): Promise<ComponentFixture<DashboardPage>> {
    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();

    await nextTick();

    const request = httpMock.expectOne((item) => item.url.includes('/dashboard'));
    request.flush({ data: payload });

    await nextTick();
    fixture.detectChanges();

    return fixture;
  }

  it('FE2-T02 does not render a zone whose visible flag is false', async () => {
    const fixture = await render(
      payloadWith({ family: { visible: false, reason: 'out_of_scope' } }),
    );

    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('app-family-switcher')).toBeNull();
    expect(element.querySelector('app-vitals-snapshot')).not.toBeNull();
    expect(element.querySelector('app-tasks-today')).not.toBeNull();
  });

  it('FE2-T04 selecting a person re-queries with profile_id', async () => {
    const fixture = await render(PAYLOAD);

    const personButton: HTMLButtonElement | null = fixture.nativeElement.querySelector(
      'button[data-profile-id="profile-2"]',
    );

    expect(personButton).not.toBeNull();
    personButton?.click();

    await nextTick();

    const scopedRequest = httpMock.expectOne((item) =>
      item.urlWithParams.includes('profile_id=profile-2'),
    );
    scopedRequest.flush({ data: PAYLOAD });

    await nextTick();
    fixture.detectChanges();

    const everyoneButton: HTMLButtonElement | null = fixture.nativeElement.querySelector(
      'button[data-profile-id="all"]',
    );

    expect(everyoneButton).not.toBeNull();
    everyoneButton?.click();

    await nextTick();

    const aggregateRequest = httpMock.expectOne('/api/v1/workspaces/ws-1/dashboard');
    aggregateRequest.flush({ data: PAYLOAD });
  });

  it('FE2-T06 elder mode renders the single-focus variant and no charts', async () => {
    window.localStorage.setItem('luckycare.elder-mode', '1');

    const fixture = await render(PAYLOAD);
    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('app-elder-focus')).not.toBeNull();
    expect(element.querySelector('app-vitals-snapshot')).toBeNull();
    expect(element.querySelector('app-tasks-today')).toBeNull();
    expect(element.querySelector('svg')).toBeNull();
    expect(element.querySelector('table')).toBeNull();
    expect(element.textContent).toContain('สิ่งที่ต้องทำตอนนี้');
    // The single-focus card always offers exactly one primary action.
    expect(element.querySelector('app-elder-focus a.btn')).not.toBeNull();
  });

  it('FE2-T08 renders the empty state instead of an error banner on 403', async () => {
    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();

    await nextTick();

    httpMock
      .expectOne((item) => item.url.includes('/dashboard'))
      .flush(
        { code: 'out_of_scope', detail: 'ไม่สามารถเข้าถึงข้อมูลนี้ได้' },
        { status: 403, statusText: 'Forbidden' },
      );

    await nextTick();
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('[data-testid="dashboard-unavailable"]')).not.toBeNull();
    expect(element.querySelector('[role="alert"]')).toBeNull();
  });

  it('FE2-T08 renders the empty state instead of an error banner on 404', async () => {
    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();

    await nextTick();

    httpMock
      .expectOne((item) => item.url.includes('/dashboard'))
      .flush(
        { code: 'out_of_scope', detail: 'ไม่สามารถเข้าถึงข้อมูลนี้ได้' },
        { status: 404, statusText: 'Not Found' },
      );

    await nextTick();
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('[data-testid="dashboard-unavailable"]')).not.toBeNull();
    expect(element.querySelector('[role="alert"]')).toBeNull();
  });
});
