import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { HealthReportPayload } from '../../core/reports/report.models';
import { BriefService } from '../../core/reports/brief.service';
import { BriefPage } from './brief.page';

const BRIEF: HealthReportPayload = {
  generatedAt: '2026-10-24T16:00:00+00:00',
  periodFrom: '2026-09-24',
  periodTo: '2026-10-24',
  sections: ['conditions', 'medications'],
  profile: { name: 'สมชาย', birthYear: 1985 },
  conditions: [{ name: 'เบาหวาน', diagnosedYear: 2018 }],
  allergies: [],
  medications: [{ name: 'Metformin', schedule: ['08:00', '20:00'] }],
  vitals: [],
  appointments: [],
  careTasksOpen: 0,
};

function createFixture(
  fetch: (token: string) => Promise<HealthReportPayload>,
): ComponentFixture<BriefPage> {
  TestBed.configureTestingModule({
    imports: [BriefPage],
    providers: [
      { provide: BriefService, useValue: { fetch } },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { paramMap: convertToParamMap({ token: 'token-1' }) } },
      },
    ],
  });

  const fixture = TestBed.createComponent(BriefPage);
  fixture.detectChanges();

  return fixture;
}

describe('BriefPage', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('BRF-PUB-T03 renders the shared brief without an auth shell', async () => {
    const fixture = createFixture(async () => BRIEF);

    await fixture.whenStable();
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('สรุปสำหรับแพทย์');
    expect(text).toContain('สมชาย');
    expect(text).toContain('เบาหวาน');
    expect(text).toContain('Metformin');
    expect(text).toContain('08:00, 20:00');
  });

  it('BRF-PUB-T04 explains an expired or invalid link', async () => {
    const fixture = createFixture(async () => {
      throw new HttpErrorResponse({ status: 404, error: { code: 'out_of_scope' } });
    });

    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('ลิงก์นี้ไม่ถูกต้องหรือหมดอายุแล้ว');
  });
});
