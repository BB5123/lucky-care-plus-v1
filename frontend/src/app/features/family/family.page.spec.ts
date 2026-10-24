import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { AuthUser, MembershipSummary } from '../../core/auth/auth.models';
import { INVITABLE_ROLES } from '../../core/family/member.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { FamilyPage } from './family.page';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const MEMBER = {
  user_id: 7,
  membership_id: 'membership-7',
  name: 'สมาชิกปัจจุบัน',
  role: 'profile_owner',
  status: 'active',
};

const PROFILE = {
  id: 'profile-1',
  person_id: 'person-1',
  display_name: 'สมาชิกปัจจุบัน',
  relationship: 'self',
  kind: 'dependent',
};

function authStub(userId: string): Partial<AuthService> {
  const user = signal<AuthUser | null>({
    id: userId,
    name: 'สมาชิกปัจจุบัน',
    email: 'me@example.test',
    phone: null,
    phone_verified_at: null,
  });

  return { user: user.asReadonly() };
}

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return {
    currentWorkspace: current.asReadonly(),
    hasWorkspace: computed(() => current() !== null),
    load: () => Promise.resolve(),
  };
}

function membersRequest(): string {
  return '/api/v1/workspaces/ws-1/members';
}

function invitationsRequest(): string {
  return '/api/v1/workspaces/ws-1/invitations';
}

function profilesRequest(): string {
  return '/api/v1/workspaces/ws-1/profiles';
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

function storedValues(storage: Storage): string[] {
  const values: string[] = [];

  for (let index = 0; index < storage.length; index += 1) {
    const key = storage.key(index);

    if (key !== null) {
      values.push(`${key}=${storage.getItem(key) ?? ''}`);
    }
  }

  return values;
}

function setInput(fixture: ComponentFixture<FamilyPage>, selector: string, value: string): void {
  const input = fixture.nativeElement.querySelector(selector) as HTMLInputElement | null;

  expect(input).not.toBeNull();
  input!.value = value;
  input!.dispatchEvent(new Event('input'));
  fixture.detectChanges();
}

function submitInvite(fixture: ComponentFixture<FamilyPage>): void {
  const form = fixture.nativeElement.querySelector(
    'form[data-testid="family-invite-form"]',
  ) as HTMLFormElement | null;

  expect(form).not.toBeNull();
  form!.dispatchEvent(new Event('submit'));
  fixture.detectChanges();
}

describe('FamilyPage', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    installLocalStorage();
    window.localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      imports: [FamilyPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: AuthService, useValue: authStub('7') },
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    window.localStorage.clear();
    sessionStorage.clear();
    Reflect.deleteProperty(navigator, 'clipboard');
    TestBed.resetTestingModule();
  });

  async function renderMembers(
    rows: readonly Record<string, unknown>[],
  ): Promise<ComponentFixture<FamilyPage>> {
    const fixture = TestBed.createComponent(FamilyPage);

    fixture.detectChanges();
    await nextTick();

    httpMock.expectOne(membersRequest()).flush({ data: rows });

    await nextTick();

    httpMock.expectOne(profilesRequest()).flush({ data: [PROFILE] });

    await nextTick();
    fixture.detectChanges();

    return fixture;
  }

  async function renderForbidden(): Promise<ComponentFixture<FamilyPage>> {
    const fixture = TestBed.createComponent(FamilyPage);

    fixture.detectChanges();
    await nextTick();

    httpMock
      .expectOne(membersRequest())
      .flush(
        { code: 'forbidden', detail: 'คุณไม่มีสิทธิ์เข้าถึงรายการนี้' },
        { status: 403, statusText: 'Forbidden' },
      );

    await nextTick();

    httpMock.expectOne(profilesRequest()).flush({ data: [PROFILE] });

    await nextTick();
    fixture.detectChanges();

    return fixture;
  }

  it('FE8-T01 parses members defensively and sorts owner, admin, caregiver, viewer', async () => {
    const fixture = await renderMembers([
      { user_id: 5, name: 'Viewer B', role: 'viewer', status: 'active' },
      { user_id: 3, name: 'Caregiver B', role: 'caregiver', status: 'active' },
      { user_id: 1, name: 'Owner', role: 'profile_owner', status: 'active' },
      { user_id: 2, name: 'Admin', role: 'workspace_admin', status: 'active' },
      { user_id: 4, name: 'Caregiver A', role: 'caregiver', status: 'active' },
      { user_id: 6, name: 'Viewer A', role: 'viewer' },
      { name: 'No Id', role: 'viewer', status: 'active' },
    ]);

    const rows = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="family-member"]'),
    ) as HTMLElement[];

    expect(rows.map((row) => row.getAttribute('data-role'))).toEqual([
      'profile_owner',
      'workspace_admin',
      'caregiver',
      'caregiver',
      'viewer',
      'viewer',
    ]);
    expect(rows[2].textContent).toContain('Caregiver A');
    expect(rows[3].textContent).toContain('Caregiver B');
    expect(rows[4].textContent).toContain('Viewer A');
    expect(rows[5].textContent).toContain('Viewer B');

    const unknownStatus = rows.find((row) => row.getAttribute('data-status') === 'unknown');

    expect(unknownStatus).toBeDefined();
    expect(unknownStatus?.textContent).toContain('ไม่ทราบสถานะ');
    expect(unknownStatus?.textContent).not.toContain('ใช้งานอยู่');
    expect(fixture.nativeElement.textContent).not.toContain('No Id');
  });

  it('FE8-T02 a 403 renders the permission explanation, not an error banner', async () => {
    const fixture = await renderForbidden();

    const explanation = fixture.nativeElement.querySelector(
      '[data-testid="family-forbidden"]',
    ) as HTMLElement | null;

    expect(explanation).not.toBeNull();
    expect(explanation?.textContent).toContain('คุณไม่มีสิทธิ์ดูรายชื่อสมาชิกในครอบครัวนี้');
    expect(explanation?.textContent).toContain('ติดต่อเจ้าของครอบครัว');
    expect(fixture.nativeElement.querySelector('[data-testid="family-error"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="family-invite-form"]')).toBeNull();
  });

  it('FE8-T03 the invite form only offers roles the API accepts', async () => {
    const fixture = await renderMembers([MEMBER]);

    const options = Array.from(
      fixture.nativeElement.querySelectorAll('#family-invite-role option'),
    ) as HTMLOptionElement[];
    const values = options.map((option) => option.value);

    expect(values).toEqual([...INVITABLE_ROLES]);
    expect(values).not.toContain('profile_owner');
    expect(values).not.toContain('platform_admin');
    expect(values).not.toContain('platform_support');
  });

  it('FE8-T04 a successful invite shows the link once, with a copy action, and never persists it', async () => {
    const fixture = await renderMembers([MEMBER]);
    const token = 'a'.repeat(64);
    const writeText = vi.fn().mockResolvedValue(undefined);

    Object.defineProperty(navigator, 'clipboard', {
      value: { writeText },
      configurable: true,
    });

    setInput(fixture, '#family-invite-email', 'guest@example.test');
    submitInvite(fixture);

    const request = httpMock.expectOne(invitationsRequest());

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      email: 'guest@example.test',
      role: 'caregiver',
    });

    request.flush({
      data: {
        id: 'invitation-1',
        email: 'guest@example.test',
        role: 'caregiver',
        expires_at: '2026-10-31T00:00:00+00:00',
        token,
      },
    });

    await nextTick();
    fixture.detectChanges();

    const success = fixture.nativeElement.querySelector(
      '[data-testid="family-invite-success"]',
    ) as HTMLElement | null;

    expect(success).not.toBeNull();
    expect(success?.textContent).toContain('ลิงก์นี้จะแสดงเพียงครั้งเดียว');

    const link = fixture.nativeElement.querySelector(
      '[data-testid="family-invite-link"]',
    ) as HTMLInputElement | null;

    expect(link).not.toBeNull();
    expect(link?.value).toContain(token);
    expect(link?.value).toContain('/invitations/');

    expect(storedValues(window.localStorage).join('\n')).not.toContain(token);
    expect(storedValues(sessionStorage).join('\n')).not.toContain(token);

    const copy = fixture.nativeElement.querySelector(
      '[data-testid="family-invite-copy"]',
    ) as HTMLButtonElement | null;

    expect(copy).not.toBeNull();
    copy?.click();
    await nextTick();
    fixture.detectChanges();

    expect(writeText).toHaveBeenCalledWith(link?.value);
    expect(
      fixture.nativeElement.querySelector('[data-testid="family-invite-copy-status"]')?.textContent,
    ).toContain('คัดลอกลิงก์แล้ว');
  });

  it('FE8-T05 blocks an invalid email client-side and shows a server 422 inline', async () => {
    const fixture = await renderMembers([MEMBER]);

    setInput(fixture, '#family-invite-email', 'not-an-email');
    submitInvite(fixture);

    httpMock.expectNone(invitationsRequest());

    const clientError = fixture.nativeElement.querySelector(
      '[data-testid="family-invite-email-error"]',
    ) as HTMLElement | null;

    expect(clientError).not.toBeNull();
    expect(clientError?.textContent).toContain('อีเมล');

    setInput(fixture, '#family-invite-email', 'guest@example.test');
    submitInvite(fixture);

    const request = httpMock.expectOne(invitationsRequest());

    request.flush(
      {
        code: 'validation_error',
        detail: 'ข้อมูลไม่ถูกต้อง',
        errors: { email: ['อีเมลนี้ถูกใช้แล้ว'] },
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );

    await nextTick();
    fixture.detectChanges();

    const serverError = fixture.nativeElement.querySelector(
      '[data-testid="family-invite-email-error"]',
    ) as HTMLElement | null;

    expect(serverError).not.toBeNull();
    expect(serverError?.textContent).toContain('อีเมลนี้ถูกใช้แล้ว');
    expect(fixture.nativeElement.querySelector('[data-testid="family-invite-success"]')).toBeNull();
  });

  it("FE8-T06 the current user's row is marked คุณ", async () => {
    const fixture = await renderMembers([
      { user_id: 8, name: 'สมาชิกอีกคน', role: 'workspace_admin', status: 'active' },
      MEMBER,
    ]);

    const rows = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="family-member"]'),
    ) as HTMLElement[];
    const selfRow = rows.find((row) => row.getAttribute('data-role') === 'profile_owner');
    const otherRow = rows.find((row) => row.getAttribute('data-role') === 'workspace_admin');

    expect(selfRow?.querySelector('[data-testid="family-member-self"]')?.textContent).toContain(
      'คุณ',
    );
    expect(otherRow?.querySelector('[data-testid="family-member-self"]')).toBeNull();
  });

  it('FE8-T07 renders no change-role, suspend or remove control', async () => {
    const fixture = await renderMembers([MEMBER]);
    const text = String(fixture.nativeElement.textContent ?? '');
    const controlLabels = Array.from(
      fixture.nativeElement.querySelectorAll('button, a') as NodeListOf<HTMLElement>,
    ).map((element) => element.textContent?.trim() ?? '');

    expect(text).not.toContain('เปลี่ยนบทบาท');
    expect(text).not.toContain('ระงับสมาชิก');
    expect(text).not.toContain('ลบสมาชิก');
    expect(text).not.toContain('นำออก');
    expect(controlLabels).not.toContain('เปลี่ยนบทบาท');
    expect(controlLabels).not.toContain('ระงับสมาชิก');
    expect(controlLabels).not.toContain('ลบสมาชิก');
    expect(controlLabels).not.toContain('นำออก');
    expect(fixture.nativeElement.querySelector('[data-testid*="role-change"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid*="suspend"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid*="remove"]')).toBeNull();
  });
});
