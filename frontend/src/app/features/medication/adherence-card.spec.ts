import { TestBed } from '@angular/core/testing';
import { AdherenceCard } from './adherence-card';
import { MedicationAdherence } from '../../core/medication/medication.models';

const ROW: MedicationAdherence = {
  plan_id: 'plan-1',
  name: 'ยาทดสอบ',
  expected: 8,
  taken: 5,
  skipped: 2,
  missed: 1,
  // Deliberately not 5/8 = 62.5: the card must print the server value.
  percent: 63,
  daily: [
    {
      date: '2026-10-22',
      expected: 2,
      taken: 1,
      skipped: 1,
      missed: 0,
      percent: 50,
    },
  ],
};

describe('AdherenceCard', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('FE4-T04 renders the API adherence numbers without recomputing them', () => {
    const fixture = TestBed.createComponent(AdherenceCard);

    fixture.componentRef.setInput('rows', [ROW]);
    fixture.componentRef.setInput('meta', {
      days: 7,
      from: '2026-10-17',
      to: '2026-10-23',
    });
    fixture.detectChanges();

    const percent = fixture.nativeElement.querySelector(
      '[data-testid="adherence-percent"]',
    ) as HTMLElement;

    expect(percent.textContent?.trim()).toBe('63%');
    expect(percent.textContent).not.toContain('62.5');

    expect(
      (fixture.nativeElement.querySelector('[data-testid="adherence-expected"]') as HTMLElement)
        .textContent,
    ).toContain('8');
    expect(
      (fixture.nativeElement.querySelector('[data-testid="adherence-taken"]') as HTMLElement)
        .textContent,
    ).toContain('5');
    expect(
      (fixture.nativeElement.querySelector('[data-testid="adherence-skipped"]') as HTMLElement)
        .textContent,
    ).toContain('2');
    expect(
      (fixture.nativeElement.querySelector('[data-testid="adherence-missed"]') as HTMLElement)
        .textContent,
    ).toContain('1');

    const bar = fixture.nativeElement.querySelector(
      '[aria-label="รับแล้ว 1 จาก 2"]',
    ) as HTMLElement;

    expect(bar.style.width).toBe('50%');
  });
});
