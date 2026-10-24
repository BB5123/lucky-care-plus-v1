import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { MembershipSummary } from '../../core/auth/auth.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { HealthPage } from './health.page';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const DASHBOARD_PAYLOAD = {
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
        ring: 'inner',
        status: 'green',
        reason: 'stable',
      },
    ],
  },
  generated_at: '2026-10-23T02:00:00+00:00',
  cache_ttl: 30,
};

const ALLERGIES_PAGE = {
  data: [
    {
      id: 'allergy-1',
      substance: 'Penicillin',
      reaction: 'ผื่น',
      severity: 'moderate',
      noted_at: '2019-05-01',
      version: 3,
      created_at: '2026-10-20T02:00:00+00:00',
      updated_at: '2026-10-22T02:00:00+00:00',
    },
  ],
  meta: { next_cursor: null, limit: 50 },
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return {
    currentWorkspace: current.asReadonly(),
    hasWorkspace: computed(() => current() !== null),
    load: () => Promise.resolve(),
    select: () => true,
    reset: () => undefined,
  };
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

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('HealthPage', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    installLocalStorage();

    TestBed.configureTestingModule({
      imports: [HealthPage],
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
    TestBed.resetTestingModule();
  });

  async function renderPage(): Promise<ComponentFixture<HealthPage>> {
    const fixture = TestBed.createComponent(HealthPage);
    fixture.detectChanges();

    await nextTick();

    httpMock
      .expectOne((item) => item.url.includes('/dashboard'))
      .flush({ data: DASHBOARD_PAYLOAD });

    await nextTick();
    fixture.detectChanges();
    await nextTick();
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.url.includes('/profiles/profile-1/measurements'))
      .flush({ data: [], meta: { next_cursor: null, limit: 50 } });

    await nextTick();
    fixture.detectChanges();

    return fixture;
  }

  it('FE3-T07 switching profile re-queries with the new profile id', async () => {
    const fixture = await renderPage();

    const mother = fixture.nativeElement.querySelector(
      'button[data-profile-id="profile-2"]',
    ) as HTMLButtonElement | null;

    expect(mother).not.toBeNull();
    mother?.click();

    fixture.detectChanges();
    await nextTick();
    fixture.detectChanges();

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-2/measurements'),
    );

    request.flush({ data: [], meta: { next_cursor: null, limit: 50 } });

    await nextTick();
    fixture.detectChanges();

    expect(mother?.getAttribute('aria-pressed')).toBe('true');
  });

  it('FE3-T08 the allergies tab renders and updates with If-Match', async () => {
    const fixture = await renderPage();

    const allergiesTab = fixture.nativeElement.querySelector(
      'button[data-tab="allergies"]',
    ) as HTMLButtonElement | null;

    expect(allergiesTab).not.toBeNull();
    allergiesTab?.click();

    fixture.detectChanges();
    await nextTick();
    fixture.detectChanges();
    await nextTick();
    fixture.detectChanges();

    httpMock
      .expectOne((item) => item.url.includes('/profiles/profile-1/allergies'))
      .flush(ALLERGIES_PAGE);

    await nextTick();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Penicillin');

    const edit = fixture.nativeElement.querySelector(
      '[data-testid="allergy-edit"]',
    ) as HTMLButtonElement | null;

    edit?.click();
    fixture.detectChanges();
    await nextTick();
    fixture.detectChanges();

    const substance = fixture.nativeElement.querySelector('#allergy-substance') as HTMLInputElement;

    substance.value = 'Penicillin G';
    substance.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    const form = fixture.nativeElement.querySelector(
      'form[data-testid="allergy-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/allergies/allergy-1'),
    );

    expect(request.request.method).toBe('PATCH');
    expect(request.request.headers.get('If-Match')).toBe('3');
    expect(request.request.body).toEqual({
      substance: 'Penicillin G',
      reaction: 'ผื่น',
      severity: 'moderate',
      noted_at: '2019-05-01',
    });

    request.flush({
      data: {
        ...ALLERGIES_PAGE.data[0],
        substance: 'Penicillin G',
        version: 4,
      },
    });

    await nextTick();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Penicillin G');
    expect(fixture.nativeElement.textContent).toContain('บันทึกแล้ว');
  });
});
