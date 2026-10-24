import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { KnowledgeAnswer } from '../../../core/knowledge/knowledge.models';
import { KnowledgeService } from '../../../core/knowledge/knowledge.service';
import { KnowledgeAsk } from './knowledge-ask';

const ANSWER: KnowledgeAnswer = {
  answer: 'ควรฉีดวัคซีนไข้หวัดใหญ่ประจำปี [A]\n\nแหล่งอ้างอิง:\n[A] ไข้หวัดใหญ่ — กรมควบคุมโรค',
  sources: [
    {
      label: 'A',
      id: 'ddc-disease-13',
      title: 'ไข้หวัดใหญ่ (Influenza)',
      publisher: 'กรมควบคุมโรค กระทรวงสาธารณสุข',
      url: 'https://ddc.moph.go.th/disease_detail.php?d=13',
      publishedAt: null,
      fetchedAt: '2026-10-24',
      reviewStatus: 'unreviewed',
    },
  ],
  disclaimer: 'ข้อมูลนี้เป็นความรู้ทั่วไปจากเอกสารของหน่วยงานราชการ',
  model: 'test-model',
  usage: { requestsToday: 1, remaining: 19 },
};

function createFixture(
  ask: (profileId: string, question: string) => Promise<KnowledgeAnswer>,
): ComponentFixture<KnowledgeAsk> {
  TestBed.configureTestingModule({
    imports: [KnowledgeAsk],
    providers: [{ provide: KnowledgeService, useValue: { ask } }],
  });

  const fixture = TestBed.createComponent(KnowledgeAsk);
  fixture.componentRef.setInput('profileId', 'profile-1');
  fixture.detectChanges();

  return fixture;
}

function typeQuestion(fixture: ComponentFixture<KnowledgeAsk>, value: string): void {
  const textarea = fixture.nativeElement.querySelector('#knowledge-question') as HTMLTextAreaElement;

  textarea.value = value;
  textarea.dispatchEvent(new Event('input'));
  fixture.detectChanges();
}

async function submit(fixture: ComponentFixture<KnowledgeAsk>): Promise<void> {
  const button = fixture.nativeElement.querySelector(
    '[data-testid="knowledge-ask-submit"]',
  ) as HTMLButtonElement;

  button.click();
  fixture.detectChanges();
  await Promise.resolve();
  fixture.detectChanges();
}

describe('KnowledgeAsk', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('G4KB-UI-T03 renders the cited answer and source link', async () => {
    const ask = vi.fn(async () => ANSWER);
    const fixture = createFixture(ask);

    typeQuestion(fixture, 'ไข้หวัดใหญ่ป้องกันอย่างไร');
    await submit(fixture);

    const text = fixture.nativeElement.textContent as string;
    const link = fixture.nativeElement.querySelector('a') as HTMLAnchorElement;

    expect(ask).toHaveBeenCalledWith('profile-1', 'ไข้หวัดใหญ่ป้องกันอย่างไร');
    expect(text).toContain('ควรฉีดวัคซีนไข้หวัดใหญ่ประจำปี [A]');
    expect(text).toContain('อ้างอิง 1 แหล่ง');
    expect(link.href).toBe('https://ddc.moph.go.th/disease_detail.php?d=13');
    expect(link.textContent).toContain('[A] ไข้หวัดใหญ่ (Influenza)');
  });

  it('G4KB-UI-T04 maps knowledge_not_found to a Thai message', async () => {
    const fixture = createFixture(
      vi.fn(async () => {
        throw new HttpErrorResponse({ status: 422, error: { code: 'knowledge_not_found' } });
      }),
    );

    typeQuestion(fixture, 'คำถามที่ไม่มีในฐานความรู้');
    await submit(fixture);

    expect(fixture.nativeElement.textContent).toContain('ไม่พบข้อมูลในฐานความรู้สำหรับคำถามนี้');
  });

  it('G4KB-UI-T05 keeps the submit button disabled until a question is typed', () => {
    const fixture = createFixture(vi.fn(async () => ANSWER));
    const button = fixture.nativeElement.querySelector(
      '[data-testid="knowledge-ask-submit"]',
    ) as HTMLButtonElement;

    expect(button.disabled).toBe(true);

    typeQuestion(fixture, 'ไข้เลือดออก');

    expect(button.disabled).toBe(false);
  });
});
