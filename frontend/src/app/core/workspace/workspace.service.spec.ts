import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { MeResponse } from '../auth/auth.models';
import { WorkspaceService } from './workspace.service';

function meResponse(): MeResponse {
  return {
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'user@example.test',
      phone: null,
      phone_verified_at: null,
      is_platform_admin: false,
    },
    workspaces: [
      {
        id: 'ws-id-1',
        workspace_id: 'ws-1',
        membership_id: 'membership-1',
        name: 'ครอบครัวหนึ่ง',
        role: 'caregiver',
        status: 'suspended',
      },
      {
        id: 'ws-id-2',
        workspace_id: 'ws-2',
        membership_id: 'membership-2',
        name: 'ครอบครัวสอง',
        role: 'profile_owner',
        status: 'active',
      },
      {
        id: 'ws-id-3',
        workspace_id: 'ws-3',
        membership_id: 'membership-3',
        name: 'ครอบครัวสาม',
        role: 'caregiver',
        status: 'active',
      },
    ],
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

describe('WorkspaceService', () => {
  let service: WorkspaceService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    installLocalStorage();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(WorkspaceService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    window.localStorage.clear();
    sessionStorage.clear();
    TestBed.resetTestingModule();
  });

  it('falls back to the first active membership and persists it', async () => {
    const loading = service.load();

    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });

    await loading;

    expect(service.workspaces().length).toBe(3);
    expect(service.hasWorkspace()).toBe(true);
    expect(service.currentWorkspace()?.workspace_id).toBe('ws-2');
    expect(window.localStorage.getItem('luckycare.workspace')).toBe('ws-2');
  });

  it('restores the stored workspace when it is still active', async () => {
    window.localStorage.setItem('luckycare.workspace', 'ws-3');

    const loading = service.load();

    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });

    await loading;

    expect(service.currentWorkspace()?.workspace_id).toBe('ws-3');
  });

  it('selects another workspace and ignores unknown ids', async () => {
    const loading = service.load();
    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });
    await loading;

    expect(service.select('ws-3')).toBe(true);
    expect(service.currentWorkspace()?.workspace_id).toBe('ws-3');
    expect(window.localStorage.getItem('luckycare.workspace')).toBe('ws-3');

    expect(service.select('ws-unknown')).toBe(false);
    expect(service.select('ws-1')).toBe(false);
    expect(service.currentWorkspace()?.workspace_id).toBe('ws-3');
  });

  it('reports no workspace when every membership is inactive', async () => {
    const response = meResponse();
    response.workspaces[1].status = 'suspended';
    response.workspaces[2].status = 'suspended';

    const loading = service.load();
    httpMock.expectOne('/api/v1/me').flush({ data: response });
    await loading;

    expect(service.hasWorkspace()).toBe(false);
    expect(service.currentWorkspace()).toBeNull();
    expect(window.localStorage.getItem('luckycare.workspace')).toBeNull();
  });

  it('reads the account lifecycle and flags a restricted account', async () => {
    const loading = service.load();
    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });
    await loading;

    const lifecycle = service.loadLifecycle();

    httpMock.expectOne('/api/v1/workspaces/ws-2').flush({
      data: {
        id: 'ws-2',
        retention_state: 'read_only',
        retention_expires_at: '2026-11-24T00:00:00+00:00',
      },
    });

    await lifecycle;

    expect(service.retentionState()).toBe('read_only');
    expect(service.isRestricted()).toBe(true);
    expect(service.lifecycle()?.retention_expires_at).toBe('2026-11-24T00:00:00+00:00');
  });

  it('ignores an unknown lifecycle value instead of guessing a state', async () => {
    const loading = service.load();
    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });
    await loading;

    const lifecycle = service.loadLifecycle();

    httpMock.expectOne('/api/v1/workspaces/ws-2').flush({
      data: { id: 'ws-2', retention_state: 'mystery', retention_expires_at: 42 },
    });

    await lifecycle;

    expect(service.retentionState()).toBeNull();
    expect(service.isRestricted()).toBe(false);
  });

  it('leaves the lifecycle unknown when the state read fails', async () => {
    const loading = service.load();
    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });
    await loading;

    const lifecycle = service.loadLifecycle();

    httpMock
      .expectOne('/api/v1/workspaces/ws-2')
      .flush({ code: 'out_of_scope' }, { status: 404, statusText: 'Not Found' });

    await lifecycle;

    expect(service.retentionState()).toBeNull();
    expect(service.isRestricted()).toBe(false);
  });

  it('reads the lifecycle once and clears it when the workspace changes', async () => {
    const loading = service.load();
    httpMock.expectOne('/api/v1/me').flush({ data: meResponse() });
    await loading;

    const firstRead = service.loadLifecycle();
    httpMock.expectOne('/api/v1/workspaces/ws-2').flush({ data: { retention_state: 'read_only' } });
    await firstRead;

    // A second call for the same workspace is served from the signal, not the
    // network (afterEach would fail on an extra request).
    await service.loadLifecycle();
    expect(service.isRestricted()).toBe(true);

    // Switching workspace drops the previous account's banner; the shell
    // reloads the new state itself.
    expect(service.select('ws-3')).toBe(true);
    expect(service.retentionState()).toBeNull();
    expect(service.isRestricted()).toBe(false);
  });
});
