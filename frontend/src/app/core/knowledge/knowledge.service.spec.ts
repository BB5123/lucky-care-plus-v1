import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../auth/auth.models';
import { WorkspaceService } from '../workspace/workspace.service';
import { KnowledgeService } from './knowledge.service';

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

const ANSWER_PAYLOAD = {
  answer: 'ควรฉีดวัคซีนไข้หวัดใหญ่ประจำปี [A]\n\nแหล่งอ้างอิง:\n[A] ไข้หวัดใหญ่ — กรมควบคุมโรค',
  sources: [
    {
      label: 'A',
      id: 'ddc-disease-13',
      title: 'ไข้หวัดใหญ่ (Influenza)',
      publisher: 'กรมควบคุมโรค กระทรวงสาธารณสุข',
      url: 'https://ddc.moph.go.th/disease_detail.php?d=13',
      published_at: null,
      fetched_at: '2026-10-24',
      review_status: 'unreviewed',
    },
  ],
  disclaimer: 'ข้อมูลนี้เป็นความรู้ทั่วไปจากเอกสารของหน่วยงานราชการ',
  model: 'test-model',
  usage: { requests_today: 1, remaining: 19 },
};

describe('KnowledgeService', () => {
  let httpMock: HttpTestingController;
  let service: KnowledgeService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
    service = TestBed.inject(KnowledgeService);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('G4KB-UI-T01 posts the trimmed question and parses the cited answer', async () => {
    const pending = service.ask('profile-1', '  ไข้หวัดใหญ่ป้องกันอย่างไร  ');

    const request = httpMock.expectOne(
      (item) =>
        item.method === 'POST' &&
        item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/ai/knowledge-answers',
    );

    expect(request.request.body).toEqual({ question: 'ไข้หวัดใหญ่ป้องกันอย่างไร' });

    request.flush({ data: ANSWER_PAYLOAD });

    const answer = await pending;

    expect(answer.answer).toContain('[A]');
    expect(answer.sources.length).toBe(1);
    expect(answer.sources[0]?.title).toBe('ไข้หวัดใหญ่ (Influenza)');
    expect(answer.sources[0]?.url).toBe('https://ddc.moph.go.th/disease_detail.php?d=13');
    expect(answer.usage.remaining).toBe(19);
    expect(answer.model).toBe('test-model');
  });

  it('G4KB-UI-T02 rejects an envelope without sources', async () => {
    const pending = service.ask('profile-1', 'ไข้หวัดใหญ่');

    httpMock
      .expectOne(
        (item) =>
          item.method === 'POST' &&
          item.url === '/api/v1/workspaces/ws-1/profiles/profile-1/ai/knowledge-answers',
      )
      .flush({ data: { answer: 'ไม่มีแหล่งอ้างอิง' } });

    await expect(pending).rejects.toThrow('malformed');
  });
});
