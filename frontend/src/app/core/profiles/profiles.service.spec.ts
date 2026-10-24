import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { ProfilesService } from './profiles.service';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const PROFILE_PAYLOAD = {
  data: [
    {
      id: 'profile-dependent',
      person_id: 'person-1',
      display_name: 'คุณแม่',
      relationship: 'dependent',
      kind: 'dependent',
    },
    {
      id: 'profile-self',
      person_id: 'person-2',
      display_name: 'คุณพ่อ',
      relationship: 'self',
      kind: 'self',
    },
    {
      id: '',
      person_id: 'person-3',
      display_name: 'แถวที่ใช้ไม่ได้',
      relationship: 'other',
      kind: 'dependent',
    },
  ],
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

describe('ProfilesService', () => {
  let service: ProfilesService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    service = TestBed.inject(ProfilesService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('I2-T04 maps the payload and selects self first', async () => {
    const loading = service.load();

    httpMock
      .expectOne((item) => item.url.endsWith('/workspaces/ws-1/profiles'))
      .flush(PROFILE_PAYLOAD);

    await loading;

    expect(service.profiles().map((profile) => profile.id)).toEqual([
      'profile-dependent',
      'profile-self',
    ]);
    expect(service.currentProfile()?.id).toBe('profile-self');
    expect(service.currentProfile()?.display_name).toBe('คุณพ่อ');
    expect(service.error()).toBeNull();
    expect(service.errorStatus()).toBeNull();
  });
});
