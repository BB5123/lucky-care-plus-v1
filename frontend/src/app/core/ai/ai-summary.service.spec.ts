import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { AiSummaryService } from './ai-summary.service';

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

const SUMMARY_PAYLOAD = {
  summary: 'สรุปแนวโน้ม: ความดันสูงขึ้นเล็กน้อย',
  disclaimer: 'คำเตือน: ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง',
  record_refs: [{ type: 'health_measurement', id: 'measurement-1' }],
  usage: { requests_today: 1, remaining: 19 },
  model: 'test-model',
};

describe('AiSummaryService', () => {
  let httpMock: HttpTestingController;
  let service: AiSummaryService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    service = TestBed.inject(AiSummaryService);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('G4UI-T01 posts to the profile ai/summaries path and parses the envelope', async () => {
    const pending = service.summarize('profile-1');

    const request = httpMock.expectOne(
      (item) =>
        item.method === 'POST' &&
        item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/ai/summaries',
    );

    expect(request.request.body).toEqual({});

    request.flush({ data: SUMMARY_PAYLOAD });

    const summary = await pending;

    expect(summary.summary).toBe('สรุปแนวโน้ม: ความดันสูงขึ้นเล็กน้อย');
    expect(summary.disclaimer).toBe('คำเตือน: ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง');
    expect(summary.recordRefs).toEqual([{ type: 'health_measurement', id: 'measurement-1' }]);
    expect(summary.usage.requestsToday).toBe(1);
    expect(summary.usage.remaining).toBe(19);
    expect(summary.model).toBe('test-model');
  });

  it('G4UI-T02 rejects a malformed envelope instead of rendering undefined', async () => {
    const pending = service.summarize('profile-1');

    httpMock
      .expectOne(
        (item) =>
          item.method === 'POST' &&
          item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/ai/summaries',
      )
      .flush({ data: { summary: 'missing the rest' } });

    await expect(pending).rejects.toThrow('malformed');
  });
});
