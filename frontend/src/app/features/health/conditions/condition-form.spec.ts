import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { MembershipSummary } from '../../../core/auth/auth.models';
import { Condition } from '../../../core/health-record/health-record.models';
import { WorkspaceService } from '../../../core/workspace/workspace.service';
import { ConditionForm } from './condition-form';

const WORKSPACE: MembershipSummary = {
  id: 'ws-id-1',
  workspace_id: 'ws-1',
  membership_id: 'membership-1',
  name: 'ครอบครัวทดสอบ',
  role: 'profile_owner',
  status: 'active',
};

const CONDITION: Condition = {
  id: 'condition-1',
  code: 'I10',
  name: 'ความดันโลหิตสูง',
  diagnosed_at: '2020-01-02',
  status: 'active',
  notes: null,
  version: 2,
  created_at: '2026-10-20T02:00:00+00:00',
  updated_at: '2026-10-22T02:00:00+00:00',
};

function workspaceStub(): Partial<WorkspaceService> {
  const current = signal<MembershipSummary | null>(WORKSPACE);

  return { currentWorkspace: current.asReadonly() };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('ConditionForm', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [ConditionForm],
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

  function render(editing: Condition | null = null): ComponentFixture<ConditionForm> {
    const fixture = TestBed.createComponent(ConditionForm);
    fixture.componentRef.setInput('profileId', 'profile-1');
    fixture.componentRef.setInput('editing', editing);
    fixture.detectChanges();

    return fixture;
  }

  function setText(
    fixture: ComponentFixture<ConditionForm>,
    selector: string,
    value: string,
  ): void {
    const input = fixture.nativeElement.querySelector(selector) as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function submit(fixture: ComponentFixture<ConditionForm>): void {
    const form = fixture.nativeElement.querySelector(
      'form[data-testid="condition-form"]',
    ) as HTMLFormElement;

    form.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
  }

  it('FE3-T04 renders a 422 errors.code inline under the code field', async () => {
    const fixture = render();

    setText(fixture, '#condition-name', 'เบาหวาน');
    setText(fixture, '#condition-code', 'E11');
    submit(fixture);

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/conditions'),
    );

    request.flush(
      {
        code: 'validation',
        title: 'Validation failed',
        detail: 'ข้อมูลที่ส่งมาไม่ถูกต้อง',
        errors: { code: ['รหัสโรคนี้ไม่ถูกต้อง'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    await nextTick();
    fixture.detectChanges();

    const codeError = fixture.nativeElement.querySelector(
      '[data-field-error="code"]',
    ) as HTMLElement | null;

    expect(codeError?.textContent).toContain('รหัสโรคนี้ไม่ถูกต้อง');
    expect(fixture.nativeElement.querySelector('[data-testid="condition-form-error"]')).toBeNull();
  });

  it('FE3-T06 a 412 on condition update shows the conflict message, not a generic error', async () => {
    const fixture = render(CONDITION);

    await nextTick();
    fixture.detectChanges();

    setText(fixture, '#condition-name', 'ความดันโลหิตสูง (แก้ไข)');
    submit(fixture);

    const request = httpMock.expectOne((item) =>
      item.url.includes('/profiles/profile-1/conditions/condition-1'),
    );

    expect(request.request.method).toBe('PATCH');
    expect(request.request.headers.get('If-Match')).toBe('2');

    request.flush(
      {
        type: 'https://api.luckycare.example/problems/version-conflict',
        code: 'version_conflict',
        status: 412,
        detail: 'ข้อมูลถูกแก้ไขโดยคนอื่น',
      },
      { status: 412, statusText: 'Precondition Failed' },
    );

    await nextTick();
    fixture.detectChanges();

    const conflict = fixture.nativeElement.querySelector(
      '[data-testid="condition-conflict"]',
    ) as HTMLElement | null;

    expect(conflict?.textContent).toContain('ข้อมูลถูกแก้ไขโดยคนอื่น');
    expect(conflict?.textContent).toContain('โหลดข้อมูลใหม่');
    expect(fixture.nativeElement.querySelector('[data-testid="condition-form-error"]')).toBeNull();
    expect(fixture.nativeElement.textContent).not.toContain('เกิดข้อผิดพลาดบางอย่าง');
  });
});
