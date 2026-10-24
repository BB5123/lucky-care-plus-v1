import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { AiSummary } from '../../../core/ai/ai-summary.models';
import { AiSummaryService } from '../../../core/ai/ai-summary.service';
import { AiSummaryPanel } from './ai-summary-panel';

const SUMMARY: AiSummary = {
  summary: 'สรุปแนวโน้ม: ความดันสูงขึ้นเล็กน้อย',
  disclaimer: 'คำเตือน: ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง',
  recordRefs: [{ type: 'health_measurement', id: 'measurement-1' }],
  usage: { requestsToday: 1, remaining: 19 },
  model: 'test-model',
};

function createFixture(
  summarize: (profileId: string) => Promise<AiSummary>,
): ComponentFixture<AiSummaryPanel> {
  TestBed.configureTestingModule({
    imports: [AiSummaryPanel],
    providers: [{ provide: AiSummaryService, useValue: { summarize } }],
  });

  const fixture = TestBed.createComponent(AiSummaryPanel);
  fixture.componentRef.setInput('profileId', 'profile-1');
  fixture.detectChanges();

  return fixture;
}

async function clickGenerate(fixture: ComponentFixture<AiSummaryPanel>): Promise<void> {
  const button = fixture.nativeElement.querySelector(
    '[data-testid="ai-summary-generate"]',
  ) as HTMLButtonElement;

  button.click();
  fixture.detectChanges();
  await Promise.resolve();
  fixture.detectChanges();
}

describe('AiSummaryPanel', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('G4UI-T03 renders the summary, disclaimer and citation count after a click', async () => {
    const summarize = vi.fn(async () => SUMMARY);
    const fixture = createFixture(summarize);

    await clickGenerate(fixture);

    const text = fixture.nativeElement.textContent as string;

    expect(summarize).toHaveBeenCalledWith('profile-1');
    expect(text).toContain('สรุปแนวโน้ม: ความดันสูงขึ้นเล็กน้อย');
    expect(text).toContain('คำเตือน: ข้อมูลนี้สรุปจากบันทึกที่ผู้ใช้บันทึกเอง');
    expect(text).toContain('อ้างอิง 1 รายการ');
  });

  it('G4UI-T04 maps the ai_disabled code to a Thai message', async () => {
    const fixture = createFixture(
      vi.fn(async () => {
        throw new HttpErrorResponse({ status: 503, error: { code: 'ai_disabled' } });
      }),
    );

    await clickGenerate(fixture);

    expect(fixture.nativeElement.textContent).toContain('ฟีเจอร์ AI ยังไม่เปิดใช้งานในระบบนี้');
  });

  it('G4UI-T05 maps the quota code to a Thai message', async () => {
    const fixture = createFixture(
      vi.fn(async () => {
        throw new HttpErrorResponse({ status: 429, error: { code: 'ai_quota_exceeded' } });
      }),
    );

    await clickGenerate(fixture);

    expect(fixture.nativeElement.textContent).toContain('ครบจำนวนที่กำหนดในวันนี้');
  });

  it('G4UI-T06 disables the button while the request is in flight', async () => {
    let resolve: (value: AiSummary) => void = () => undefined;
    const summarize = vi.fn(
      () =>
        new Promise<AiSummary>((done) => {
          resolve = done;
        }),
    );
    const fixture = createFixture(summarize);

    const button = fixture.nativeElement.querySelector(
      '[data-testid="ai-summary-generate"]',
    ) as HTMLButtonElement;

    button.click();
    fixture.detectChanges();

    expect(button.disabled).toBe(true);

    resolve(SUMMARY);
    await Promise.resolve();
    fixture.detectChanges();

    expect(button.disabled).toBe(false);
  });
});
