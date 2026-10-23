import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../core/auth/auth.models';
import { MedicationPlan } from '../../core/medication/medication.models';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { DiscontinuePlanForm } from './discontinue-plan-form';

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
  version: 3,
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

describe('DiscontinuePlanForm', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DiscontinuePlanForm],
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

  function render(): ComponentFixture<DiscontinuePlanForm> {
    const fixture = TestBed.createComponent(DiscontinuePlanForm);

    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.componentRef.setInput('plan', PLAN);
    fixture.detectChanges();

    return fixture;
  }

  function setReason(fixture: ComponentFixture<DiscontinuePlanForm>, value: string): void {
    const input = fixture.nativeElement.querySelector(
      '[data-testid="discontinue-reason"]',
    ) as HTMLTextAreaElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function submit(fixture: ComponentFixture<DiscontinuePlanForm>): void {
    const form = fixture.nativeElement.querySelector(
      'form[data-testid="discontinue-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  it('FE4-T02 a discontinue needs a reason and sends the plan version as If-Match', async () => {
    const fixture = render();

    setReason(fixture, 'ยา');
    submit(fixture);

    expect(
      fixture.nativeElement.querySelector('[data-testid="discontinue-reason-error"]'),
    ).not.toBeNull();

    httpMock.expectNone((item) => item.url.includes('/discontinue'));

    setReason(fixture, 'แพทย์สั่งหยุดหลังตรวจเลือด');
    submit(fixture);

    const request = httpMock.expectOne((item) => item.url.includes('/discontinue'));

    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('If-Match')).toBe('3');
    expect(request.request.body).toEqual({ reason: 'แพทย์สั่งหยุดหลังตรวจเลือด' });

    request.flush({
      data: {
        ...PLAN,
        status: 'discontinued',
        discontinued_reason: 'แพทย์สั่งหยุดหลังตรวจเลือด',
        discontinued_at: '2026-10-23T02:00:00+00:00',
        version: 4,
      },
    });

    await nextTick();
    fixture.detectChanges();
  });
});
