import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../core/auth/auth.models';
import { MedicationPlan } from '../../core/medication/medication.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { MedicationRecordForm } from './medication-record-form';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const PLAN: MedicationPlan = {
  id: 'plan-1',
  name: 'ยาเม็ดทดสอบ',
  dosage: '500 mg',
  form: 'tablet',
  route: 'oral',
  doses_per_day: 2,
  schedule_times: ['08:00', '20:00'],
  starts_on: '2026-10-01',
  ends_on: null,
  prescribed_by: null,
  instructions: null,
  status: 'active',
  discontinued_reason: null,
  discontinued_at: null,
  source: 'manual',
  recorded_by: null,
  version: 2,
  created_at: null,
  updated_at: null,
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('MedicationRecordForm', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [MedicationRecordForm],
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

  function render(): ComponentFixture<MedicationRecordForm> {
    const fixture = TestBed.createComponent(MedicationRecordForm);

    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.componentRef.setInput('plans', [PLAN]);
    fixture.componentRef.setInput('defaultPlanId', PLAN.id);
    fixture.detectChanges();

    return fixture;
  }

  function chooseStatus(fixture: ComponentFixture<MedicationRecordForm>, status: string): void {
    const select = fixture.nativeElement.querySelector(
      '[data-testid="record-status"]',
    ) as HTMLSelectElement;

    select.value = status;
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  function submit(fixture: ComponentFixture<MedicationRecordForm>): void {
    const form = fixture.nativeElement.querySelector(
      'form[data-testid="record-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  it('FE4-T03 a skipped dose needs a note while a taken dose does not', async () => {
    const fixture = render();

    chooseStatus(fixture, 'skipped');
    submit(fixture);

    expect(fixture.nativeElement.querySelector('[data-field-error="note"]')).not.toBeNull();
    httpMock.expectNone((item) => item.url.includes('/medication-records'));

    chooseStatus(fixture, 'taken');
    submit(fixture);

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/medication-records'),
    );

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      plan_id: 'plan-1',
      status: 'taken',
    });

    request.flush({
      data: {
        id: 'record-1',
        plan_id: 'plan-1',
        status: 'taken',
        scheduled_for: null,
        recorded_at: '2026-10-23T02:00:00+00:00',
        note: null,
        source: 'manual',
        recorded_by: null,
        version: 1,
        created_at: null,
        updated_at: null,
      },
    });

    await nextTick();

    const adherence = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/medication-adherence'),
    );

    adherence.flush({
      data: [],
      meta: {
        days: 7,
        from: '2026-10-17',
        to: '2026-10-23',
      },
    });

    await nextTick();
    fixture.detectChanges();
  });
});
