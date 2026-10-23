import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../core/auth/auth.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { MedicationPlanForm } from './medication-plan-form';

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

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('MedicationPlanForm', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [MedicationPlanForm],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WorkspaceService, useValue: workspaceStub() },
      ],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  function render(): ComponentFixture<MedicationPlanForm> {
    const fixture = TestBed.createComponent(MedicationPlanForm);

    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.detectChanges();

    return fixture;
  }

  function setText(fixture: ComponentFixture<MedicationPlanForm>, selector: string, value: string) {
    const input = fixture.nativeElement.querySelector(selector) as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  it('FE4-T01 the time rows are the single source of doses_per_day', async () => {
    const fixture = render();
    const addTime = fixture.nativeElement.querySelector(
      '[data-testid="add-time"]',
    ) as HTMLButtonElement;

    addTime.click();
    addTime.click();
    fixture.detectChanges();

    const rows = fixture.nativeElement.querySelectorAll(
      '[data-testid="schedule-time"]',
    ) as NodeListOf<HTMLInputElement>;
    const counter = fixture.nativeElement.querySelector(
      '[data-testid="doses-per-day"]',
    ) as HTMLElement;

    expect(rows.length).toBe(3);
    expect(counter.textContent?.trim()).toBe('3');

    setText(fixture, '#medication-name', 'ยาทดสอบ');
    setText(fixture, '#medication-starts-on', '2026-10-01');

    const form = fixture.nativeElement.querySelector(
      'form[data-testid="medication-plan-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/medication-plans'),
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.body.doses_per_day).toBe(3);
    expect(request.request.body.schedule_times).toEqual(['08:00', '12:00', '18:00']);
    expect(request.request.body.name).toBe('ยาทดสอบ');

    request.flush({
      data: {
        id: 'plan-1',
        name: 'ยาทดสอบ',
        dosage: null,
        form: 'tablet',
        route: 'oral',
        doses_per_day: 3,
        schedule_times: ['08:00', '12:00', '18:00'],
        starts_on: '2026-10-01',
        ends_on: null,
        prescribed_by: null,
        instructions: null,
        status: 'active',
        discontinued_reason: null,
        discontinued_at: null,
        source: 'manual',
        recorded_by: null,
        version: 1,
        created_at: null,
        updated_at: null,
      },
    });

    await nextTick();
    fixture.detectChanges();
  });
});
