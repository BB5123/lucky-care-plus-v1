import { TestBed } from '@angular/core/testing';
import { AlertsZone } from '../../../../core/dashboard/dashboard.models';
import { GENERIC_ALERT_MESSAGE } from '../alert-messages';
import { PriorityBanner } from './priority-banner';

function alertsZone(messageKeys: readonly string[]): AlertsZone {
  return {
    visible: true,
    items: messageKeys.map((messageKey, index) => ({
      level: index === 0 ? 'critical' : 'warning',
      person_id: 'person-1',
      code: 'test',
      task_id: null,
      message_key: messageKey,
    })),
  };
}

describe('PriorityBanner', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [PriorityBanner] });
  });

  afterEach(() => {
    TestBed.resetTestingModule();
  });

  it('FE2-T07 translates message_key locally and falls back for an unknown key', () => {
    const fixture = TestBed.createComponent(PriorityBanner);
    fixture.componentRef.setInput(
      'zone',
      alertsZone(['dashboard.alert.tasks_overdue', 'dashboard.alert.unknown_future_key']),
    );
    fixture.componentRef.setInput('personNames', { 'person-1': 'คุณพ่อ' });
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';

    expect(text).toContain('มีงานดูแลที่เลยกำหนดแล้ว');
    expect(text).toContain(GENERIC_ALERT_MESSAGE);
    expect(text).not.toContain('dashboard.alert.tasks_overdue');
    expect(text).not.toContain('dashboard.alert.unknown_future_key');
  });

  it('renders nothing when the alert list is empty', () => {
    const fixture = TestBed.createComponent(PriorityBanner);
    fixture.componentRef.setInput('zone', { visible: true, items: [] });
    fixture.detectChanges();

    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('[aria-live="polite"]')).toBeNull();
  });
});
