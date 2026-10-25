import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import { EMPTY } from 'rxjs';
import { AuthUser } from '../../core/auth/auth.models';
import { AuthService } from '../../core/auth/auth.service';
import { platformAdminGuard } from '../../core/auth/platform-admin.guard';
import { NotificationsService } from '../../core/notifications/notifications.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { AppShell } from '../../shared/layout/app-shell/app-shell';
import { KnowledgeAdminPage } from './knowledge-admin.page';

const SOURCES_URL = '/api/v1/admin/knowledge/sources';

const REVIEWED_SOURCE = {
  id: 'ddc-disease-44',
  title: 'โรคเบาหวานและการดูแลตนเอง',
  publisher: 'กรมควบคุมโรค',
  url: 'https://example.test/diabetes',
  category: 'disease',
  language: 'th',
  fetched_at: '2026-01-10',
  review_status: 'reviewed',
  reviewed_by: 'Dr. Test',
  reviewed_at: '2026-02-01',
  stale: false,
};

const UNREVIEWED_STALE_SOURCE = {
  id: 'moph-nutrition-7',
  title: 'โภชนาการสำหรับผู้สูงอายุ',
  publisher: 'กระทรวงสาธารณสุข',
  url: 'https://example.test/nutrition',
  category: 'nutrition',
  language: 'th',
  fetched_at: '2024-01-01',
  review_status: 'unreviewed',
  reviewed_by: null,
  reviewed_at: null,
  stale: true,
};

function authStub(isPlatformAdmin: boolean): Partial<AuthService> {
  const user = signal<AuthUser | null>({
    id: 'user-1',
    name: 'ผู้ใช้ทดสอบ',
    email: 'admin@example.test',
    phone: null,
    phone_verified_at: null,
    is_platform_admin: isPlatformAdmin,
  });

  return {
    user: user.asReadonly(),
    isPlatformAdmin: computed(() => user()?.is_platform_admin === true),
    refreshMe: () => EMPTY,
    logout: () => EMPTY,
  };
}

function workspaceStub(): Partial<WorkspaceService> {
  const workspaces = signal([]);
  const current = signal(null);

  return {
    activeWorkspaces: workspaces.asReadonly(),
    currentWorkspace: current.asReadonly(),
    retentionState: signal(null).asReadonly(),
    loadLifecycle: () => Promise.resolve(),
    load: () => Promise.resolve(),
    select: () => true,
    reset: () => undefined,
  };
}

function notificationsStub(): Partial<NotificationsService> {
  return {
    unreadCount: signal(0).asReadonly(),
    refreshUnreadCount: () => Promise.resolve(),
    reset: () => undefined,
  };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

function setInput(element: HTMLInputElement, value: string): void {
  element.value = value;
  element.dispatchEvent(new Event('input'));
}

function hasSourceQuery(
  urlWithParams: string,
  expected: Readonly<Record<string, string>>,
): boolean {
  const parsed = new URL(urlWithParams, 'http://localhost');

  return (
    parsed.pathname === SOURCES_URL &&
    Object.entries(expected).every(([key, value]) => parsed.searchParams.get(key) === value)
  );
}

describe('KnowledgeAdminPage', () => {
  let httpMock: HttpTestingController | null = null;

  afterEach(() => {
    try {
      httpMock?.verify();
    } finally {
      httpMock = null;
      vi.restoreAllMocks();
      TestBed.resetTestingModule();
    }
  });

  function configure(options: { admin?: boolean } = {}): void {
    TestBed.configureTestingModule({
      imports: [KnowledgeAdminPage, AppShell],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: AuthService, useValue: authStub(options.admin ?? true) },
        { provide: WorkspaceService, useValue: workspaceStub() },
        { provide: NotificationsService, useValue: notificationsStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  }

  async function renderAdmin(
    rows: readonly Record<string, unknown>[],
  ): Promise<ComponentFixture<KnowledgeAdminPage>> {
    const fixture = TestBed.createComponent(KnowledgeAdminPage);

    fixture.detectChanges();
    await nextTick();

    httpMock!.expectOne(SOURCES_URL).flush({ data: rows });

    await nextTick();
    fixture.detectChanges();

    return fixture;
  }

  function rowElements(fixture: ComponentFixture<KnowledgeAdminPage>): HTMLElement[] {
    return Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="knowledge-admin-row"]'),
    ) as HTMLElement[];
  }

  it('KAD-T01 a non-admin is redirected and the nav entry is absent', async () => {
    configure({ admin: false });

    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    const guardResult = TestBed.runInInjectionContext(() =>
      platformAdminGuard(
        {} as ActivatedRouteSnapshot,
        { url: '/admin/knowledge' } as RouterStateSnapshot,
      ),
    );

    expect(guardResult instanceof UrlTree).toBe(true);
    expect((guardResult as UrlTree).toString()).toBe('/dashboard');

    const fixture = TestBed.createComponent(KnowledgeAdminPage);

    fixture.detectChanges();
    await nextTick();

    expect(navigate).toHaveBeenCalledWith('/dashboard');
    expect(httpMock!.match(() => true)).toHaveLength(0);

    const shell = TestBed.createComponent(AppShell);

    shell.detectChanges();
    await nextTick();

    expect(
      shell.nativeElement.querySelector('[data-testid="shell-admin-knowledge-link"]'),
    ).toBeNull();
  });

  it('KAD-T02 the table renders sources with status chips and the stale badge', async () => {
    configure();

    const fixture = await renderAdmin([REVIEWED_SOURCE, UNREVIEWED_STALE_SOURCE]);
    const rows = rowElements(fixture);

    expect(rows).toHaveLength(2);

    expect(rows[0].textContent).toContain('โรคเบาหวานและการดูแลตนเอง');
    expect(rows[0].textContent).toContain('กรมควบคุมโรค');
    expect(rows[0].querySelector('[data-testid="knowledge-admin-status"]')?.textContent).toContain(
      'ผ่านการตรวจแล้ว',
    );
    expect(rows[0].querySelector('[data-testid="knowledge-admin-stale"]')).toBeNull();

    expect(rows[1].textContent).toContain('โภชนาการสำหรับผู้สูงอายุ');
    expect(rows[1].textContent).toContain('กระทรวงสาธารณสุข');
    expect(rows[1].querySelector('[data-testid="knowledge-admin-status"]')?.textContent).toContain(
      'ยังไม่ตรวจ',
    );
    expect(rows[1].querySelector('[data-testid="knowledge-admin-stale"]')?.textContent).toContain(
      'เก่า',
    );

    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-count"]')?.textContent,
    ).toContain('แสดง 2 จาก 2 แหล่ง');
  });

  it('KAD-T03 the status filter and search box request the right parameters once', async () => {
    configure();

    const fixture = await renderAdmin([REVIEWED_SOURCE, UNREVIEWED_STALE_SOURCE]);
    const select = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-status-filter"]',
    ) as HTMLSelectElement;

    select.value = 'unreviewed';
    select.dispatchEvent(new Event('change'));
    await nextTick();

    const filtered = httpMock!.expectOne((request) =>
      hasSourceQuery(request.urlWithParams, { status: 'unreviewed' }),
    );

    filtered.flush({ data: [UNREVIEWED_STALE_SOURCE] });
    await nextTick();
    fixture.detectChanges();

    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-count"]')?.textContent,
    ).toContain('แสดง 1 จาก 2 แหล่ง');

    const search = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-search"]',
    ) as HTMLInputElement;

    setInput(search, 'หัว');
    setInput(search, 'หัวใจ');
    fixture.detectChanges();

    await new Promise((resolve) => setTimeout(resolve, 350));

    const searches = httpMock!.match((request) =>
      hasSourceQuery(request.urlWithParams, {
        status: 'unreviewed',
        q: 'หัวใจ',
      }),
    );

    expect(searches).toHaveLength(1);
    expect(
      httpMock!.match((request) =>
        hasSourceQuery(request.urlWithParams, {
          status: 'unreviewed',
          q: 'หัว',
        }),
      ),
    ).toHaveLength(0);

    searches[0].flush({ data: [UNREVIEWED_STALE_SOURCE] });
    await nextTick();
    fixture.detectChanges();
  });

  it('KAD-T04 the review dialog requires a reviewer of at least 3 characters', async () => {
    configure();

    const fixture = await renderAdmin([UNREVIEWED_STALE_SOURCE]);

    (
      fixture.nativeElement.querySelector(
        '[data-testid="knowledge-admin-review"]',
      ) as HTMLButtonElement
    ).click();
    fixture.detectChanges();

    const reviewer = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-reviewer"]',
    ) as HTMLInputElement;

    setInput(reviewer, 'ab');
    fixture.detectChanges();

    const form = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-review-dialog"] form',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await nextTick();

    expect(httpMock!.match((request) => request.method === 'POST')).toHaveLength(0);
    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-reviewer-error"]')
        ?.textContent,
    ).toContain('อย่างน้อย 3 ตัวอักษร');
  });

  it('KAD-T05 a successful review updates the row and shows the Thai success line', async () => {
    configure();

    const fixture = await renderAdmin([UNREVIEWED_STALE_SOURCE]);

    (
      fixture.nativeElement.querySelector(
        '[data-testid="knowledge-admin-review"]',
      ) as HTMLButtonElement
    ).click();
    fixture.detectChanges();

    const reviewer = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-reviewer"]',
    ) as HTMLInputElement;
    const rebuild = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-rebuild"]',
    ) as HTMLInputElement;

    expect(rebuild.checked).toBe(true);

    setInput(reviewer, 'Dr. Test');
    fixture.detectChanges();

    (
      fixture.nativeElement.querySelector(
        '[data-testid="knowledge-admin-review-dialog"] form',
      ) as HTMLFormElement
    ).dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await nextTick();

    const request = httpMock!.expectOne(
      (item) => item.method === 'POST' && item.url === `${SOURCES_URL}/moph-nutrition-7/review`,
    );

    expect(request.request.body).toEqual({ reviewer: 'Dr. Test', build: true });

    request.flush({
      data: {
        ...UNREVIEWED_STALE_SOURCE,
        review_status: 'reviewed',
        reviewed_by: 'Dr. Test',
        reviewed_at: '2026-10-25',
        stale: false,
      },
    });

    await nextTick();
    fixture.detectChanges();

    const rows = rowElements(fixture);

    expect(rows).toHaveLength(1);
    expect(rows[0].querySelector('[data-testid="knowledge-admin-status"]')?.textContent).toContain(
      'ผ่านการตรวจแล้ว',
    );
    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-success"]')?.textContent,
    ).toContain('บันทึกการตรวจทานแล้ว');
    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-review-dialog"]'),
    ).toBeNull();
  });

  it('KAD-T06 a 403 renders the calm explanation, not an error banner', async () => {
    configure();

    const fixture = TestBed.createComponent(KnowledgeAdminPage);

    fixture.detectChanges();
    await nextTick();

    httpMock!
      .expectOne(SOURCES_URL)
      .flush(
        { code: 'forbidden', detail: 'คุณไม่มีสิทธิ์เข้าถึงรายการนี้' },
        { status: 403, statusText: 'Forbidden' },
      );

    await nextTick();
    fixture.detectChanges();

    const forbidden = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-forbidden"]',
    ) as HTMLElement | null;

    expect(forbidden).not.toBeNull();
    expect(forbidden?.textContent).toContain('ไม่สามารถเปิดหน้านี้ได้');
    expect(forbidden?.textContent).toContain('ติดต่อผู้ดูแลระบบ');
    expect(fixture.nativeElement.querySelector('[data-testid="knowledge-admin-error"]')).toBeNull();
  });

  it('KAD-T07 a 422 shows the field error next to the input', async () => {
    configure();

    const fixture = await renderAdmin([UNREVIEWED_STALE_SOURCE]);

    (
      fixture.nativeElement.querySelector(
        '[data-testid="knowledge-admin-review"]',
      ) as HTMLButtonElement
    ).click();
    fixture.detectChanges();

    const reviewer = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-reviewer"]',
    ) as HTMLInputElement;

    setInput(reviewer, 'Dr. Test');
    fixture.detectChanges();

    (
      fixture.nativeElement.querySelector(
        '[data-testid="knowledge-admin-review-dialog"] form',
      ) as HTMLFormElement
    ).dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await nextTick();

    httpMock!
      .expectOne(
        (item) => item.method === 'POST' && item.url === `${SOURCES_URL}/moph-nutrition-7/review`,
      )
      .flush(
        {
          code: 'validation',
          detail: 'ข้อมูลที่ส่งมาไม่ถูกต้อง',
          errors: { reviewer: ['ชื่อผู้ตรวจทานสั้นเกินไป'] },
        },
        { status: 422, statusText: 'Unprocessable Entity' },
      );

    await nextTick();
    fixture.detectChanges();

    const fieldError = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-admin-reviewer-error"]',
    ) as HTMLElement | null;

    expect(fieldError).not.toBeNull();
    expect(fieldError?.textContent).toContain('ชื่อผู้ตรวจทานสั้นเกินไป');
    expect(reviewer.getAttribute('aria-invalid')).toBe('true');
    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-success"]'),
    ).toBeNull();
    expect(
      fixture.nativeElement.querySelector('[data-testid="knowledge-admin-review-dialog"]'),
    ).not.toBeNull();
  });

  it('KAD-T08 never renders a source body or text field', async () => {
    configure();

    const body = 'ข้อความเนื้อหาลับของแหล่งความรู้';
    const fixture = await renderAdmin([
      {
        ...REVIEWED_SOURCE,
        body,
        text: `${body} ฉบับเต็ม`,
        content: `${body} ฉบับย่อ`,
      },
    ]);

    const text = String(fixture.nativeElement.textContent ?? '');

    expect(text).not.toContain(body);
    expect(text).not.toContain('ฉบับเต็ม');
    expect(text).not.toContain('ฉบับย่อ');
    expect(text).toContain(REVIEWED_SOURCE.title);
  });

  it('KAD-T09 the page itself refuses to load for a non-admin even without the route guard', async () => {
    // Defence in depth: KAD-T01 proves the *guard* redirects. This proves the
    // component does too, so removing the guard alone cannot silently expose the
    // table (found by a cross-model review of this change).
    configure({ admin: false });

    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    const fixture = TestBed.createComponent(KnowledgeAdminPage);
    fixture.detectChanges();

    expect(navigate).toHaveBeenCalledWith('/dashboard');

    // And it must not fetch anything: the redirect is the whole behaviour.
    expect(fixture.nativeElement.querySelector('table')).toBeNull();
  });
});
