import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { EMPTY } from 'rxjs';
import { AuthUser, MembershipSummary } from '../../../core/auth/auth.models';
import { AuthService } from '../../../core/auth/auth.service';
import { RetentionState, WorkspaceService } from '../../../core/workspace/workspace.service';
import { AppShell } from './app-shell';

const USER: AuthUser = {
  id: 'user-1',
  name: 'สมาชิกทดสอบ',
  email: 'member@example.com',
  phone: null,
  phone_verified_at: '2026-10-01T00:00:00+00:00',
  is_platform_admin: false,
};

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

function authStub(): Partial<AuthService> {
  const user = signal<AuthUser | null>(USER);

  return {
    user: user.asReadonly(),
    isPlatformAdmin: computed(() => user()?.is_platform_admin === true),
    logout: () => EMPTY,
  };
}

function workspaceStub(state: RetentionState | null = null): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);
  const list = signal<MembershipSummary[]>([WORKSPACE]);
  const retention = signal<RetentionState | null>(state);

  return {
    activeWorkspaces: list.asReadonly(),
    currentWorkspace: current.asReadonly(),
    hasWorkspace: computed(() => current() !== null),
    retentionState: retention.asReadonly(),
    loadLifecycle: () => Promise.resolve(),
    load: () => Promise.resolve(),
    select: () => true,
    reset: () => undefined,
  };
}

function delivery(id: string): Record<string, unknown> {
  return {
    id,
    channel: 'in_app',
    template_key: 'task.due_soon',
    status: 'sent',
    attempts: 1,
    scheduled_for: null,
    sent_at: '2026-10-24T08:00:00+00:00',
    read_at: null,
    error_code: null,
    subject_type: 'care_task',
    subject_id: 'task-1',
    created_at: '2026-10-24T08:00:00+00:00',
  };
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

describe('AppShell', () => {
  let httpMock: HttpTestingController;

  function configureShell(state: RetentionState | null = null): void {
    TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: AuthService, useValue: authStub() },
        { provide: WorkspaceService, useValue: workspaceStub(state) },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  }

  beforeEach(() => {
    installLocalStorage();
    configureShell();
  });

  afterEach(() => {
    httpMock.verify();
    window.localStorage.clear();
    TestBed.resetTestingModule();
  });

  it('FE5-T09 the shell badge reflects the unread count', async () => {
    const fixture = TestBed.createComponent(AppShell);

    fixture.detectChanges();
    await nextTick();

    const requests = httpMock.match(
      (item) =>
        item.url.includes('/me/notifications/deliveries') &&
        item.urlWithParams.includes('unread=true'),
    );

    expect(requests.length).toBeGreaterThan(0);

    for (const request of requests) {
      request.flush({
        data: [delivery('notification-1'), delivery('notification-2')],
        meta: { next_cursor: null, limit: 100 },
      });
    }

    await nextTick();
    fixture.detectChanges();

    const badge = fixture.nativeElement.querySelector(
      '[data-testid="shell-notification-badge"]',
    ) as HTMLElement | null;

    expect(badge).not.toBeNull();
    expect(badge?.textContent).toContain('2');

    const link = fixture.nativeElement.querySelector(
      '[data-testid="shell-notifications-link"]',
    ) as HTMLAnchorElement | null;

    expect(link?.getAttribute('href')).toBe('/notifications');
  });

  it('FE5-T10 the shell shows a retention banner for a read-only account', async () => {
    TestBed.resetTestingModule();
    configureShell('read_only');

    const fixture = TestBed.createComponent(AppShell);

    fixture.detectChanges();
    await nextTick();

    for (const request of httpMock.match(
      (item) =>
        item.url.includes('/me/notifications/deliveries') &&
        item.urlWithParams.includes('unread=true'),
    )) {
      request.flush({ data: [], meta: { next_cursor: null, limit: 100 } });
    }

    await nextTick();
    fixture.detectChanges();

    const banner = fixture.nativeElement.querySelector(
      '[data-testid="retention-banner"]',
    ) as HTMLElement | null;

    expect(banner).not.toBeNull();
    expect(banner?.textContent).toContain('อ่านอย่างเดียว');
    expect(banner?.querySelector('a')?.getAttribute('href')).toBe('/family');
  });

  it('FE5-T11 the shell shows no banner for an active account', async () => {
    const fixture = TestBed.createComponent(AppShell);

    fixture.detectChanges();
    await nextTick();

    for (const request of httpMock.match(
      (item) =>
        item.url.includes('/me/notifications/deliveries') &&
        item.urlWithParams.includes('unread=true'),
    )) {
      request.flush({ data: [], meta: { next_cursor: null, limit: 100 } });
    }

    await nextTick();
    fixture.detectChanges();

    const banner = fixture.nativeElement.querySelector('[data-testid="retention-banner"]');

    expect(banner).toBeNull();
  });
});
