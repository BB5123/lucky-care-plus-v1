import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../../core/auth/auth.models';
import { WorkspaceService } from '../../../core/workspace/workspace.service';
import { MeasurementForm } from './measurement-form';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const CREATED_MEASUREMENT = {
  id: 'measurement-new',
  type: 'blood_pressure',
  value: 120,
  secondary_value: 80,
  unit: 'mmHg',
  context: null,
  note: null,
  source: 'manual',
  recorded_at: '2026-10-23T02:00:00+00:00',
  recorded_by: 'user-1',
  version: 1,
  created_at: '2026-10-23T02:00:00+00:00',
  updated_at: '2026-10-23T02:00:00+00:00',
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('MeasurementForm', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [MeasurementForm],
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

  function render(): ComponentFixture<MeasurementForm> {
    const fixture = TestBed.createComponent(MeasurementForm);
    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.detectChanges();

    return fixture;
  }

  function setText(
    fixture: ComponentFixture<MeasurementForm>,
    selector: string,
    value: string,
  ): void {
    const input = fixture.nativeElement.querySelector(selector) as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function setSelect(
    fixture: ComponentFixture<MeasurementForm>,
    selector: string,
    value: string,
  ): void {
    const select = fixture.nativeElement.querySelector(selector) as HTMLSelectElement;

    select.value = value;
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
  }

  function submit(fixture: ComponentFixture<MeasurementForm>): void {
    const form = fixture.nativeElement.querySelector(
      'form[data-testid="measurement-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  it('FE3-T02 sends exactly the contract fields for every type', async () => {
    const fixture = render();

    setText(fixture, '#measurement-value', '120');
    setText(fixture, '#measurement-secondary', '80');
    submit(fixture);

    const bloodPressure = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/measurements'),
    );

    expect(bloodPressure.request.method).toBe('POST');
    expect(bloodPressure.request.body).toEqual({
      type: 'blood_pressure',
      value: 120,
      secondary_value: 80,
    });
    bloodPressure.flush({ data: CREATED_MEASUREMENT });

    await nextTick();
    fixture.detectChanges();

    setSelect(fixture, '#measurement-type', 'blood_glucose');
    setText(fixture, '#measurement-value', '110');
    setSelect(fixture, '#measurement-context', 'fasting');
    submit(fixture);

    const bloodGlucose = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/measurements'),
    );

    expect(bloodGlucose.request.body).toEqual({
      type: 'blood_glucose',
      value: 110,
      context: 'fasting',
    });
    bloodGlucose.flush({ data: { ...CREATED_MEASUREMENT, type: 'blood_glucose', value: 110 } });

    await nextTick();
    fixture.detectChanges();

    setSelect(fixture, '#measurement-type', 'heart_rate');
    setText(fixture, '#measurement-value', '72');
    submit(fixture);

    const heartRate = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/measurements'),
    );

    expect(heartRate.request.body).toEqual({
      type: 'heart_rate',
      value: 72,
    });
    heartRate.flush({ data: { ...CREATED_MEASUREMENT, type: 'heart_rate', value: 72 } });

    await nextTick();
    fixture.detectChanges();

    const singleValueTypes = [
      { type: 'weight', value: '68.5', unit: 'kg' },
      { type: 'spo2', value: '98', unit: '%' },
      { type: 'temperature', value: '37.2', unit: '°C' },
    ] as const;

    for (const { type, value, unit } of singleValueTypes) {
      setSelect(fixture, '#measurement-type', type);
      setText(fixture, '#measurement-value', value);
      submit(fixture);

      const request = httpMock.expectOne((item) =>
        item.url.includes('/profiles/profile-1/measurements'),
      );

      expect(request.request.body).toEqual({ type, value: Number(value) });
      request.flush({
        data: { ...CREATED_MEASUREMENT, type, value: Number(value), unit },
      });

      await nextTick();
      fixture.detectChanges();
    }
  });

  it('FE3-T03 out-of-range client validation blocks submit and shows the range', async () => {
    const fixture = render();

    setSelect(fixture, '#measurement-type', 'heart_rate');
    setText(fixture, '#measurement-value', '500');
    submit(fixture);

    await nextTick();
    fixture.detectChanges();

    httpMock.expectNone((item) => item.url.includes('/profiles/profile-1/measurements'));

    const error = fixture.nativeElement.querySelector(
      '[data-field-error="value"]',
    ) as HTMLElement | null;

    expect(error).not.toBeNull();
    expect(error?.textContent).toContain('20–250');
    expect(error?.textContent).toContain('bpm');
  });

  it('binds a 422 errors{} entry under the matching measurement field', async () => {
    const fixture = render();

    setText(fixture, '#measurement-value', '120');
    setText(fixture, '#measurement-secondary', '80');
    submit(fixture);

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/measurements'),
    );

    request.flush(
      {
        code: 'validation',
        title: 'Validation failed',
        detail: 'ข้อมูลที่ส่งมาไม่ถูกต้อง',
        errors: { value: ['ค่าความดันสูงเกินช่วงที่บันทึกได้'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    await nextTick();
    fixture.detectChanges();

    const error = fixture.nativeElement.querySelector(
      '[data-field-error="value"]',
    ) as HTMLElement | null;

    expect(error?.textContent).toContain('ค่าความดันสูงเกินช่วงที่บันทึกได้');
    expect(
      fixture.nativeElement.querySelector('[data-testid="measurement-form-error"]'),
    ).toBeNull();
  });
});
