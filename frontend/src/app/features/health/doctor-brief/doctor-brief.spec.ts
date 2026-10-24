import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HealthReport, ReportShare } from '../../../core/reports/report.models';
import { ReportService } from '../../../core/reports/report.service';
import { DoctorBrief } from './doctor-brief';

const REPORT: HealthReport = {
  id: 'report-1',
  kind: 'doctor_brief',
  periodFrom: '2026-09-24',
  periodTo: '2026-10-24',
  createdAt: '2026-10-24T16:00:00+00:00',
  payload: {
    sections: ['conditions', 'allergies', 'medications', 'vitals', 'appointments', 'care_tasks'],
    profile: { name: 'สมชาย', birthYear: 1985 },
    conditions: [{ name: 'เบาหวาน', diagnosedYear: 2018 }],
    allergies: [{ substance: 'Penicillin', severity: 'severe' }],
    medications: [{ name: 'Metformin', schedule: ['08:00', '20:00'] }],
    vitals: [{ type: 'blood_pressure', count: 2 }],
    appointments: [{ status: 'attended' }],
    careTasksOpen: 2,
  },
};

const SHARE: ReportShare = {
  id: 'share-1',
  url: 'https://app.example/briefs/token-1',
  expiresAt: '2026-10-24T16:30:00+00:00',
};

function createFixture(
  create: (profileId: string) => Promise<HealthReport>,
  share: (profileId: string, reportId: string) => Promise<ReportShare>,
): ComponentFixture<DoctorBrief> {
  TestBed.configureTestingModule({
    imports: [DoctorBrief],
    providers: [{ provide: ReportService, useValue: { create, share } }],
  });

  const fixture = TestBed.createComponent(DoctorBrief);
  fixture.componentRef.setInput('profileId', 'profile-1');
  fixture.detectChanges();

  return fixture;
}

async function click(fixture: ComponentFixture<DoctorBrief>, testId: string): Promise<void> {
  const button = fixture.nativeElement.querySelector(
    `[data-testid="${testId}"]`,
  ) as HTMLButtonElement;

  button.click();
  fixture.detectChanges();
  await Promise.resolve();
  fixture.detectChanges();
}

describe('DoctorBrief', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('BRF-UI-T04 creates a brief and renders the section counts', async () => {
    const fixture = createFixture(
      async () => REPORT,
      async () => SHARE,
    );

    await click(fixture, 'doctor-brief-create');

    const text = fixture.nativeElement.textContent as string;

    expect(text).toContain('สมชาย');
    expect(text).toContain('โรคประจำตัว 1 รายการ');
    expect(text).toContain('งานที่ยังเปิดอยู่ 2 งาน');
  });

  it('BRF-UI-T05 shares the brief and shows the capability link', async () => {
    const fixture = createFixture(
      async () => REPORT,
      async () => SHARE,
    );

    await click(fixture, 'doctor-brief-create');
    await click(fixture, 'doctor-brief-share');

    const link = fixture.nativeElement.querySelector(
      '[data-testid="doctor-brief-link"] a',
    ) as HTMLAnchorElement;

    expect(link.href).toBe(SHARE.url);
    expect(fixture.nativeElement.textContent).toContain('แชร์ให้แพทย์ 30 นาที');
  });

  it('BRF-UI-T06 explains a 403 on create', async () => {
    const fixture = createFixture(
      async () => {
        throw new HttpErrorResponse({ status: 403, error: { code: 'forbidden' } });
      },
      async () => SHARE,
    );

    await click(fixture, 'doctor-brief-create');

    expect(fixture.nativeElement.textContent).toContain('ต้องมีสิทธิ์ส่งออก');
  });
});
