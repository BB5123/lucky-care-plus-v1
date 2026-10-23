/**
 * Local translation for `alerts.items[].message_key`.
 *
 * The API intentionally sends a key, never free text with a health value
 * (N-02). The client owns the Thai copy; an unknown future key still gets a
 * safe, generic sentence instead of leaking the key to the user.
 */

const ALERT_MESSAGES: Readonly<Record<string, string>> = {
  'dashboard.alert.vital_critical': 'มีค่าสุขภาพที่ต้องดูแลทันที',
  'dashboard.alert.vital_low': 'มีค่าสุขภาพต่ำกว่าเกณฑ์ ควรตรวจสอบ',
  'dashboard.alert.vital_high': 'มีค่าสุขภาพสูงกว่าเกณฑ์ ควรตรวจสอบ',
  'dashboard.alert.tasks_overdue': 'มีงานดูแลที่เลยกำหนดแล้ว',
  'dashboard.alert.appointment_soon': 'มีนัดหมายที่ต้องยืนยันวันนี้',
};

export const GENERIC_ALERT_MESSAGE = 'มีเรื่องที่ต้องตรวจสอบในครอบครัว';

export function translateAlertMessage(messageKey: string): string {
  return ALERT_MESSAGES[messageKey] ?? GENERIC_ALERT_MESSAGE;
}
