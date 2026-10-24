/**
 * Typed mirror of the self-service notification endpoints.
 *
 * The ledger contains metadata only: channel, template key, status and
 * timestamps. `params` is intentionally absent from the API, so a notification
 * row is rendered from a local template dictionary, never from a server
 * sentence (N-02, MOBILE_DESIGN §7).
 */

export const NOTIFICATION_CHANNELS = ['in_app', 'push', 'line'] as const;

export type NotificationChannel = (typeof NOTIFICATION_CHANNELS)[number];

export const NOTIFICATION_STATUSES = ['queued', 'sent', 'failed', 'skipped'] as const;

export type NotificationDigest = 'immediate' | 'daily';

export type NotificationLocale = 'th' | 'en';

export interface NotificationDelivery {
  readonly id: string;
  readonly channel: string;
  readonly template_key: string;
  readonly status: string;
  readonly attempts: number;
  readonly scheduled_for: string | null;
  readonly sent_at: string | null;
  readonly read_at: string | null;
  readonly error_code: string | null;
  readonly subject_type: string | null;
  readonly subject_id: string | null;
  readonly created_at: string | null;
}

export interface NotificationChannelSettings {
  readonly in_app: boolean;
  readonly push: boolean;
  readonly line: boolean;
}

export interface NotificationQuietHours {
  readonly start: string | null;
  readonly end: string | null;
}

export interface NotificationPreferences {
  readonly id: string;
  readonly locale: NotificationLocale;
  readonly channels: NotificationChannelSettings;
  readonly quiet_hours: NotificationQuietHours | null;
  readonly digest: NotificationDigest;
  readonly updated_at: string | null;
}

export interface NotificationDeliveryFilters {
  readonly status?: string | null;
  readonly channel?: string | null;
  readonly unread?: boolean;
  readonly limit?: number;
  readonly cursor?: string | null;
}

export interface NotificationPreferencePatch {
  readonly locale?: NotificationLocale;
  readonly digest?: NotificationDigest;
  readonly channels?: Partial<NotificationChannelSettings>;
  readonly quiet_hours?: { readonly start: string; readonly end: string } | null;
}

export interface NotificationDeliveryPage {
  readonly items: readonly NotificationDelivery[];
  readonly nextCursor: string | null;
  readonly limit: number;
}

const TEMPLATE_FALLBACK = 'มีการแจ้งเตือนใหม่';

const TEMPLATE_COPY: Readonly<Record<string, string>> = {
  'task.assigned': 'มีงานดูแลใหม่ที่ได้รับมอบหมาย',
  'task.due_soon': 'มีงานดูแลใกล้ถึงกําหนด',
  'task.escalated': 'มีงานดูแลที่ต้องได้รับการยืนยัน',
  'appt.reminder': 'มีการแจ้งเตือนนัดหมาย',
  'appt.confirmed': 'นัดหมายได้รับการยืนยันแล้ว',
  'bg.activated': 'เปิดใช้งานการติดตามระดับน้ําตาลแล้ว',
  'medication.due': 'ถึงเวลาบันทึกการกินยา',
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function readObject(value: unknown): Record<string, unknown> {
  return isRecord(value) ? value : {};
}

function readArray(value: unknown): readonly unknown[] {
  return Array.isArray(value) ? value : [];
}

function readString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

function readNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function readBoolean(value: unknown): boolean {
  return value === true;
}

export function isNotificationChannel(value: string): value is NotificationChannel {
  return (NOTIFICATION_CHANNELS as readonly string[]).includes(value);
}

export function parseNotificationDelivery(value: unknown): NotificationDelivery | null {
  const raw = readObject(value);
  const id = readString(raw['id']);
  const channel = readString(raw['channel']);
  const templateKey = readString(raw['template_key']);

  if (id === null || id === '' || channel === null || templateKey === null) {
    return null;
  }

  return {
    id,
    channel,
    template_key: templateKey,
    status: readString(raw['status']) ?? 'queued',
    attempts: readNumber(raw['attempts']) ?? 0,
    scheduled_for: readString(raw['scheduled_for']),
    sent_at: readString(raw['sent_at']),
    read_at: readString(raw['read_at']),
    error_code: readString(raw['error_code']),
    subject_type: readString(raw['subject_type']),
    subject_id: readString(raw['subject_id']),
    created_at: readString(raw['created_at']),
  };
}

export function parseNotificationDeliveryPage(value: unknown): NotificationDeliveryPage {
  const raw = readObject(value);
  const meta = readObject(raw['meta']);
  const items = readArray(raw['data'])
    .map((item) => parseNotificationDelivery(item))
    .filter((item): item is NotificationDelivery => item !== null);

  return {
    items,
    nextCursor: readString(meta['next_cursor']),
    limit: readNumber(meta['limit']) ?? 20,
  };
}

export function parseNotificationPreferences(value: unknown): NotificationPreferences | null {
  const raw = readObject(value);
  const id = readString(raw['id']);

  if (id === null || id === '') {
    return null;
  }

  const rawChannels = readObject(raw['channels']);
  const rawQuietHours = readObject(raw['quiet_hours']);
  const quietStart = readString(rawQuietHours['start']);
  const quietEnd = readString(rawQuietHours['end']);

  return {
    id,
    locale: readString(raw['locale']) === 'en' ? 'en' : 'th',
    channels: {
      in_app: readBoolean(rawChannels['in_app']),
      push: readBoolean(rawChannels['push']),
      line: readBoolean(rawChannels['line']),
    },
    quiet_hours:
      quietStart === null && quietEnd === null ? null : { start: quietStart, end: quietEnd },
    digest: readString(raw['digest']) === 'daily' ? 'daily' : 'immediate',
    updated_at: readString(raw['updated_at']),
  };
}

/** Local Thai copy for a delivery, keyed by the template key (never a server sentence). */
export function notificationTemplateLabel(templateKey: string): string {
  return TEMPLATE_COPY[templateKey] ?? TEMPLATE_FALLBACK;
}

export function notificationChannelLabel(channel: string): string {
  switch (channel) {
    case 'in_app':
      return 'ในแอป';
    case 'push':
      return 'พุช';
    case 'line':
      return 'LINE';
    default:
      return 'ช่องทางอื่น';
  }
}

export function mergeNotificationDeliveries(
  current: readonly NotificationDelivery[],
  incoming: readonly NotificationDelivery[],
): readonly NotificationDelivery[] {
  const seen = new Set(current.map((delivery) => delivery.id));
  const added = incoming.filter((delivery) => {
    if (seen.has(delivery.id)) {
      return false;
    }

    seen.add(delivery.id);

    return true;
  });

  return [...current, ...added];
}
