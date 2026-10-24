import { ComponentFixture, TestBed } from '@angular/core/testing';
import {
  DataExportError,
  DataExportLink,
  DataExportRequestResult,
  DataExportService,
  DataExportSnapshot,
} from '../../core/export/data-export.service';
import { DATA_EXPORT_POLL_INTERVAL_MS, DataExportCard } from './data-export-card';

const PROFILE_ID = 'profile-1';
const EXPORT_ID = 'export-1';

const QUEUED: DataExportRequestResult = { id: EXPORT_ID, status: 'queued' };

const READY: DataExportSnapshot = {
  id: EXPORT_ID,
  status: 'ready',
  expires_at: '2026-10-26T10:00:00+00:00',
  byte_size: 2048,
  error_code: null,
};

const LINK: DataExportLink = {
  url: 'https://files.example.test/exports/export-1?signature=abc',
  expires_at: '2026-10-25T10:15:00+00:00',
};

const HEALTH_VALUE = 'ความดัน 180/120';
const OBJECT_KEY = 'exports/tenant-1/export-1.json';

function createFixture(service: Partial<DataExportService>): ComponentFixture<DataExportCard> {
  TestBed.configureTestingModule({
    imports: [DataExportCard],
    providers: [{ provide: DataExportService, useValue: service }],
  });

  const fixture = TestBed.createComponent(DataExportCard);
  fixture.componentRef.setInput('profileId', PROFILE_ID);
  fixture.detectChanges();

  return fixture;
}

function query(fixture: ComponentFixture<DataExportCard>, selector: string): HTMLElement | null {
  return fixture.nativeElement.querySelector(selector) as HTMLElement | null;
}

function button(fixture: ComponentFixture<DataExportCard>, testId: string): HTMLButtonElement {
  const element = query(fixture, `[data-testid="${testId}"]`);

  expect(element).not.toBeNull();

  return element as HTMLButtonElement;
}

function click(fixture: ComponentFixture<DataExportCard>, testId: string): void {
  button(fixture, testId).click();
  fixture.detectChanges();
}

async function nextTick(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
}

async function settle(fixture: ComponentFixture<DataExportCard>): Promise<void> {
  await nextTick();
  fixture.detectChanges();
  await nextTick();
  fixture.detectChanges();
}

describe('DataExportCard', () => {
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    TestBed.resetTestingModule();
  });

  it('XUI-T01 renders the explanation and the request action', () => {
    const fixture = createFixture({
      request: vi.fn(async () => QUEUED),
      status: vi.fn(async () => READY),
      link: vi.fn(async () => LINK),
    });
    const text = String(fixture.nativeElement.textContent ?? '');

    expect(text).toContain('ขอสำเนาข้อมูลของฉัน');
    expect(text).toContain('ไม่รวมรูปภาพเอกสาร');
    expect(text).toContain('ข้อมูลเมตา');
    expect(button(fixture, 'export-request').textContent).toContain('ขอข้อมูล');
  });

  it('XUI-T02 requesting calls the service and shows the queued state', async () => {
    const request = vi.fn(async () => QUEUED);
    const fixture = createFixture({
      request,
      status: vi.fn(async () => READY),
      link: vi.fn(async () => LINK),
    });

    click(fixture, 'export-request');
    await settle(fixture);

    expect(request).toHaveBeenCalledWith(PROFILE_ID);
    expect(query(fixture, '[data-testid="export-status"]')?.textContent).toContain('อยู่ในคิว');
    expect(query(fixture, '[data-testid="export-refresh"]')).not.toBeNull();
    expect(query(fixture, '[data-testid="export-download"]')).toBeNull();

    fixture.destroy();
  });

  it('XUI-T03 polling stops once the export is ready, and the size/expiry render', async () => {
    vi.useFakeTimers();

    const status = vi.fn(async () => READY);
    const fixture = createFixture({
      request: vi.fn(async () => QUEUED),
      status,
      link: vi.fn(async () => LINK),
    });

    click(fixture, 'export-request');
    await Promise.resolve();
    await Promise.resolve();
    fixture.detectChanges();

    expect(query(fixture, '[data-testid="export-status"]')?.textContent).toContain('อยู่ในคิว');

    await vi.advanceTimersByTimeAsync(DATA_EXPORT_POLL_INTERVAL_MS);
    fixture.detectChanges();

    expect(status).toHaveBeenCalledTimes(1);
    expect(status).toHaveBeenCalledWith(PROFILE_ID, EXPORT_ID);
    expect(query(fixture, '[data-testid="export-size"]')?.textContent).toContain('2 KB');
    expect(query(fixture, '[data-testid="export-expiry"]')?.textContent).not.toContain('ไม่ทราบ');

    await vi.advanceTimersByTimeAsync(DATA_EXPORT_POLL_INTERVAL_MS * 3);

    expect(status).toHaveBeenCalledTimes(1);

    fixture.destroy();
  });

  it('XUI-T04 download calls link and opens the returned URL', async () => {
    const link = vi.fn(async () => LINK);
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);
    const fixture = createFixture({
      request: vi.fn(async () => QUEUED),
      status: vi.fn(async () => READY),
      link,
    });

    click(fixture, 'export-request');
    await settle(fixture);
    click(fixture, 'export-refresh');
    await settle(fixture);

    expect(query(fixture, '[data-testid="export-download"]')).not.toBeNull();

    click(fixture, 'export-download');
    await settle(fixture);

    expect(link).toHaveBeenCalledWith(PROFILE_ID, EXPORT_ID);
    expect(open).toHaveBeenCalledWith(LINK.url, '_blank');

    fixture.destroy();
  });

  it('XUI-T05 429 renders the wait line and disables the action', async () => {
    const fixture = createFixture({
      request: vi.fn(async () => {
        throw new DataExportError('rate_limited', 429, 'rate limited', 3600);
      }),
      status: vi.fn(async () => READY),
      link: vi.fn(async () => LINK),
    });

    click(fixture, 'export-request');
    await settle(fixture);

    const waitLine = query(fixture, '[data-testid="export-rate-limit"]');

    expect(waitLine).not.toBeNull();
    expect(waitLine?.textContent).toContain('1 ชั่วโมง');
    expect(button(fixture, 'export-request').disabled).toBe(true);
    expect(query(fixture, '[data-testid="export-error"]')).toBeNull();
  });

  it('XUI-T06 403 renders the calm explanation, not an error banner', async () => {
    const fixture = createFixture({
      request: vi.fn(async () => {
        throw new DataExportError('not_permitted', 403, 'not permitted');
      }),
      status: vi.fn(async () => READY),
      link: vi.fn(async () => LINK),
    });

    click(fixture, 'export-request');
    await settle(fixture);

    const explanation = query(fixture, '[data-testid="export-permission"]');

    expect(explanation).not.toBeNull();
    expect(explanation?.textContent).toContain('ยังไม่รวมการขอสำเนาข้อมูล');
    expect(explanation?.textContent).toContain('เจ้าของครอบครัว');
    expect(query(fixture, '[data-testid="export-error"]')).toBeNull();
    expect(fixture.nativeElement.querySelector('.alert-error')).toBeNull();
  });

  it('XUI-T07 410 swaps the ready state back to ขอข้อมูลใหม่', async () => {
    const link = vi.fn(async () => {
      throw new DataExportError('expired', 410, 'expired');
    });
    const fixture = createFixture({
      request: vi.fn(async () => QUEUED),
      status: vi.fn(async () => READY),
      link,
    });

    click(fixture, 'export-request');
    await settle(fixture);
    click(fixture, 'export-refresh');
    await settle(fixture);

    expect(query(fixture, '[data-testid="export-download"]')).not.toBeNull();

    click(fixture, 'export-download');
    await settle(fixture);

    expect(query(fixture, '[data-testid="export-download"]')).toBeNull();
    expect(button(fixture, 'export-request').textContent).toContain('ขอข้อมูลใหม่');
    expect(query(fixture, '[data-testid="export-expired"]')).not.toBeNull();

    fixture.destroy();
  });

  it('XUI-T08 renders no health value and no object key', async () => {
    const readyWithExtras = {
      ...READY,
      values: HEALTH_VALUE,
      object_key: OBJECT_KEY,
      detail: HEALTH_VALUE,
    } as unknown as DataExportSnapshot;
    const fixture = createFixture({
      request: vi.fn(async () => QUEUED),
      status: vi.fn(async () => readyWithExtras),
      link: vi.fn(async () => LINK),
    });

    click(fixture, 'export-request');
    await settle(fixture);
    click(fixture, 'export-refresh');
    await settle(fixture);

    const text = String(fixture.nativeElement.textContent ?? '');

    expect(text).not.toContain(HEALTH_VALUE);
    expect(text).not.toContain('180/120');
    expect(text).not.toContain(OBJECT_KEY);
    expect(text).toContain('2 KB');

    fixture.destroy();
  });
});
