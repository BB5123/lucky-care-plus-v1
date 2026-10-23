import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { NotificationCentrePage } from './notification-centre.page';

function delivery(
  id: string,
  overrides: Readonly<Record<string, unknown>> = {},
): Record<string, unknown> {
  return {
    id,
    channel: 'in_app',
    template_key: 'task.due_soon',
    status: 'sent',
    attempts: 1,
    scheduled_for: null,
    sent_at: '2026-10-24T08:00:00+00:00',
    read_at: null,
    error_code: null,
    subject_type: 'care_task',
    subject_id: 'task-1',
    created_at: '2026-10-24T08:00:00+00:00',
    ...overrides,
  };
}

function preferences(overrides: Readonly<Record<string, unknown>> = {}): unknown {
  return {
    id: 'preference-1',
    locale: 'th',
    channels: { in_app: true, push: true, line: false },
    quiet_hours: null,
    digest: 'immediate',
    updated_at: '2026-10-24T08:00:00+00:00',
    ...overrides,
  };
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

async function render(): Promise<ComponentFixture<NotificationCentrePage>> {
  const fixture = TestBed.createComponent(NotificationCentrePage);

  fixture.detectChanges();
  await nextTick();

  return fixture;
}

function deliveriesRequest(httpMock: HttpTestingController) {
  return httpMock.expectOne(
    (item) =>
      item.url.includes('/me/notifications/deliveries') && item.urlWithParams.includes('limit=50'),
  );
}

describe('NotificationCentrePage', () => {
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [NotificationCentrePage],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpMock.verify();
    TestBed.resetTestingModule();
  });

  it('FE5-T06 puts unread first and shows the channel label', async () => {
    const fixture = await render();

    deliveriesRequest(httpMock).flush({
      data: [
        delivery('read-new', {
          read_at: '2026-10-24T10:00:00+00:00',
          created_at: '2026-10-24T09:00:00+00:00',
          channel: 'line',
          template_key: 'appt.reminder',
        }),
        delivery('unread-old', {
          created_at: '2026-10-24T08:00:00+00:00',
          channel: 'push',
          template_key: 'task.assigned',
        }),
        delivery('unread-new', {
          created_at: '2026-10-24T10:00:00+00:00',
          channel: 'in_app',
          status: 'failed',
          template_key: 'task.due_soon',
        }),
      ],
      meta: { next_cursor: null, limit: 50 },
    });

    await nextTick();
    fixture.detectChanges();

    const rows = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="notification-row"]'),
    ) as HTMLElement[];

    expect(rows.map((row) => row.getAttribute('data-delivery-id'))).toEqual([
      'unread-new',
      'unread-old',
      'read-new',
    ]);
    expect(rows[0].textContent).toContain('มีงานดูแลใกล้ถึงกําหนด');
    expect(rows[0].textContent).toContain('ในแอป');
    expect(rows[1].textContent).toContain('มีงานดูแลใหม่ที่ได้รับมอบหมาย');
    expect(rows[1].textContent).toContain('พุช');
    expect(rows[2].textContent).toContain('LINE');
    expect(rows[0].querySelector('[data-testid="delivery-failed"]')?.textContent).toContain(
      'ส่งไม่สําเร็จ',
    );
    expect(rows.length).toBe(3);
  });

  it('FE5-T07 marks read optimistically and rolls back when the request fails', async () => {
    const fixture = await render();

    deliveriesRequest(httpMock).flush({
      data: [delivery('delivery-1')],
      meta: { next_cursor: null, limit: 50 },
    });

    await nextTick();
    fixture.detectChanges();

    const rowBefore = fixture.nativeElement.querySelector(
      '[data-delivery-id="delivery-1"]',
    ) as HTMLElement;

    expect(rowBefore.getAttribute('data-unread')).toBe('true');

    rowBefore.click();
    fixture.detectChanges();

    const rowOptimistic = fixture.nativeElement.querySelector(
      '[data-delivery-id="delivery-1"]',
    ) as HTMLElement;

    expect(rowOptimistic.getAttribute('data-unread')).toBe('false');

    const readRequest = httpMock.expectOne(
      (item) => item.url.endsWith('/delivery-1/read') && item.method === 'POST',
    );

    readRequest.flush(
      { code: 'server_error', detail: 'ไม่สามารถบันทึกได้' },
      { status: 500, statusText: 'Server Error' },
    );

    await nextTick();
    fixture.detectChanges();

    const rowRolledBack = fixture.nativeElement.querySelector(
      '[data-delivery-id="delivery-1"]',
    ) as HTMLElement;

    expect(rowRolledBack.getAttribute('data-unread')).toBe('true');
    expect(fixture.nativeElement.querySelector('[role="alert"]')?.textContent).toContain(
      'ไม่สามารถอัปเดตสถานะการอ่านได้',
    );
  });

  it('FE5-T08 saving preferences PATCHes only the changed fields', async () => {
    const fixture = await render();

    deliveriesRequest(httpMock).flush({
      data: [],
      meta: { next_cursor: null, limit: 50 },
    });

    await nextTick();
    fixture.detectChanges();

    const settingsTab = fixture.nativeElement.querySelector(
      '[data-testid="notification-tab-settings"]',
    ) as HTMLButtonElement;

    settingsTab.click();

    await nextTick();

    const preferencesRequest = httpMock.expectOne(
      (item) => item.url.endsWith('/me/notification-preferences') && item.method === 'GET',
    );

    preferencesRequest.flush({ data: preferences() });

    await nextTick();
    fixture.detectChanges();

    const push = fixture.nativeElement.querySelector(
      '[data-testid="channel-push"]',
    ) as HTMLInputElement;

    expect(push.checked).toBe(true);
    push.click();
    fixture.detectChanges();

    const save = fixture.nativeElement.querySelector(
      '[data-testid="save-preferences"]',
    ) as HTMLButtonElement;

    expect(save.disabled).toBe(false);

    const form = fixture.nativeElement.querySelector('form') as HTMLFormElement;

    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));

    const patchRequest = httpMock.expectOne(
      (item) => item.url.endsWith('/me/notification-preferences') && item.method === 'PATCH',
    );

    expect(patchRequest.request.body).toEqual({ channels: { push: false } });

    patchRequest.flush({
      data: preferences({ channels: { in_app: true, push: false, line: false } }),
    });

    await nextTick();
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('บันทึกการตั้งค่าแล้ว');
  });
});
