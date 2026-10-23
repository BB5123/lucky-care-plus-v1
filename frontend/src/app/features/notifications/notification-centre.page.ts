import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { formatRelativeThai } from '../dashboard/relative-time';
import {
  NotificationChannel,
  NotificationChannelSettings,
  NotificationDelivery,
  NotificationDigest,
  NotificationLocale,
  NotificationPreferencePatch,
  notificationChannelLabel,
  notificationTemplateLabel,
} from '../../core/notifications/notifications.models';
import { NotificationsService } from '../../core/notifications/notifications.service';

type NotificationTab = 'inbox' | 'settings';

interface ChannelOption {
  readonly id: NotificationChannel;
  readonly label: string;
  readonly hint: string;
}

const EMPTY_CHANNELS: NotificationChannelSettings = {
  in_app: true,
  push: true,
  line: false,
};

function timestampOf(value: string | null): number {
  if (value === null || value === '') {
    return 0;
  }

  const timestamp = Date.parse(value);

  return Number.isNaN(timestamp) ? 0 : timestamp;
}

/**
 * Notification centre: the delivery inbox and the user's own preferences.
 *
 * Delivery rows contain no message body and no health data. The visible copy
 * comes from a local dictionary keyed by `template_key`, and an unknown key
 * falls back to a neutral Thai sentence.
 */
@Component({
  selector: 'app-notification-centre-page',
  templateUrl: './notification-centre.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NotificationCentrePage {
  private readonly notifications = inject(NotificationsService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly channelOptions: readonly ChannelOption[] = [
    { id: 'in_app', label: 'ในแอป', hint: 'แสดงในกล่องแจ้งเตือนของ LuckyCare+' },
    { id: 'push', label: 'พุช', hint: 'แจ้งเตือนบนโทรศัพท์' },
    { id: 'line', label: 'LINE', hint: 'ส่งข้อความผ่าน LINE' },
  ];

  protected readonly deliveries = computed(() =>
    [...this.notifications.deliveries()].sort((left, right) => {
      const leftUnread = left.read_at === null ? 0 : 1;
      const rightUnread = right.read_at === null ? 0 : 1;

      if (leftUnread !== rightUnread) {
        return leftUnread - rightUnread;
      }

      return timestampOf(right.created_at) - timestampOf(left.created_at);
    }),
  );

  protected readonly loading = this.notifications.deliveriesLoading;
  protected readonly deliveriesError = this.notifications.deliveriesError;
  protected readonly nextCursor = this.notifications.deliveriesNextCursor;
  protected readonly preferences = this.notifications.preferences;
  protected readonly preferencesLoading = this.notifications.preferencesLoading;
  protected readonly saving = this.notifications.preferencesSaving;
  protected readonly preferencesError = this.notifications.preferencesError;

  protected readonly activeTab = signal<NotificationTab>('inbox');
  protected readonly announcement = signal<string | null>(null);
  protected readonly actionError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);

  protected readonly draftChannels = signal<NotificationChannelSettings>(EMPTY_CHANNELS);
  protected readonly draftQuietStart = signal('');
  protected readonly draftQuietEnd = signal('');
  protected readonly draftDigest = signal<NotificationDigest>('immediate');
  protected readonly draftLocale = signal<NotificationLocale>('th');

  protected readonly hasChanges = computed(() => {
    const current = this.preferences();

    if (current === null) {
      return false;
    }

    const channels = this.draftChannels();

    if (
      channels.in_app !== current.channels.in_app ||
      channels.push !== current.channels.push ||
      channels.line !== current.channels.line
    ) {
      return true;
    }

    if (
      this.draftQuietStart() !== (current.quiet_hours?.start ?? '') ||
      this.draftQuietEnd() !== (current.quiet_hours?.end ?? '')
    ) {
      return true;
    }

    return this.draftDigest() !== current.digest || this.draftLocale() !== current.locale;
  });

  private readonly now = signal(Date.now());
  private clockTimer: ReturnType<typeof setInterval> | null = null;

  constructor() {
    this.clockTimer = setInterval(() => this.now.set(Date.now()), 30_000);

    this.destroyRef.onDestroy(() => {
      if (this.clockTimer !== null) {
        clearInterval(this.clockTimer);
      }
    });

    void this.notifications.loadDeliveries({ limit: 50 });
  }

  protected selectTab(tab: NotificationTab): void {
    if (this.activeTab() === tab) {
      return;
    }

    this.activeTab.set(tab);
    this.formError.set(null);

    if (tab === 'settings' && this.notifications.preferences() === null) {
      void this.loadSettings();
    }
  }

  protected retryDeliveries(): void {
    void this.notifications.loadDeliveries({ limit: 50 });
  }

  protected loadMoreDeliveries(): void {
    const cursor = this.nextCursor();

    if (cursor === null) {
      return;
    }

    void this.notifications.loadDeliveries({ limit: 50, cursor }, true);
  }

  protected templateLabel(delivery: NotificationDelivery): string {
    return notificationTemplateLabel(delivery.template_key);
  }

  protected channelLabel(channel: string): string {
    return notificationChannelLabel(channel);
  }

  protected relativeLabel(delivery: NotificationDelivery): string {
    return formatRelativeThai(delivery.created_at, this.now()) ?? 'ไม่ทราบเวลา';
  }

  protected async markRead(delivery: NotificationDelivery): Promise<void> {
    if (delivery.read_at !== null) {
      return;
    }

    this.actionError.set(null);

    const ok = await this.notifications.markRead(delivery.id);

    if (!ok) {
      this.actionError.set('ไม่สามารถอัปเดตสถานะการอ่านได้ กรุณาลองใหม่');
    }
  }

  protected retryPreferences(): void {
    void this.loadSettings();
  }

  protected draftChannel(channel: NotificationChannel): boolean {
    return this.draftChannels()[channel];
  }

  protected setChannel(channel: NotificationChannel, event: Event): void {
    const target = event.target as HTMLInputElement | null;

    if (target === null) {
      return;
    }

    this.formError.set(null);
    this.draftChannels.update((current) => {
      const next = { ...current };
      next[channel] = target.checked;

      return next;
    });
  }

  protected setTime(which: 'start' | 'end', event: Event): void {
    const target = event.target as HTMLInputElement | null;

    if (target === null) {
      return;
    }

    this.formError.set(null);

    if (which === 'start') {
      this.draftQuietStart.set(target.value);
    } else {
      this.draftQuietEnd.set(target.value);
    }
  }

  protected setDigest(event: Event): void {
    const target = event.target as HTMLSelectElement | null;
    const value = target?.value ?? '';

    if (value === 'immediate' || value === 'daily') {
      this.draftDigest.set(value);
    }
  }

  protected setLocale(event: Event): void {
    const target = event.target as HTMLSelectElement | null;
    const value = target?.value ?? '';

    if (value === 'th' || value === 'en') {
      this.draftLocale.set(value);
    }
  }

  protected submitPreferences(event: Event): void {
    event.preventDefault();
    void this.persistPreferences();
  }

  private async loadSettings(): Promise<void> {
    const ok = await this.notifications.loadPreferences();

    if (ok) {
      this.syncDraft();
    }
  }

  private syncDraft(): void {
    const preferences = this.notifications.preferences();

    if (preferences === null) {
      return;
    }

    this.draftChannels.set({ ...preferences.channels });
    this.draftQuietStart.set(preferences.quiet_hours?.start ?? '');
    this.draftQuietEnd.set(preferences.quiet_hours?.end ?? '');
    this.draftDigest.set(preferences.digest);
    this.draftLocale.set(preferences.locale);
  }

  private async persistPreferences(): Promise<void> {
    const patch = this.buildPatch();

    if (patch === null) {
      return;
    }

    if (Object.keys(patch).length === 0) {
      this.announce('ไม่มีการเปลี่ยนแปลง');
      return;
    }

    const ok = await this.notifications.updatePreferences(patch);

    if (ok) {
      this.formError.set(null);
      this.syncDraft();
      this.announce('บันทึกการตั้งค่าแล้ว');
    }
  }

  /**
   * Sends only the fields that differ from the loaded preference. A channel
   * object therefore contains one key when one toggle changed, not all three.
   */
  private buildPatch(): NotificationPreferencePatch | null {
    const current = this.preferences();

    if (current === null) {
      return null;
    }

    const patch: {
      locale?: NotificationLocale;
      digest?: NotificationDigest;
      channels?: Partial<NotificationChannelSettings>;
      quiet_hours?: { start: string; end: string } | null;
    } = {};

    const draftChannels = this.draftChannels();
    const channels: {
      in_app?: boolean;
      push?: boolean;
      line?: boolean;
    } = {};

    if (draftChannels.in_app !== current.channels.in_app) {
      channels.in_app = draftChannels.in_app;
    }

    if (draftChannels.push !== current.channels.push) {
      channels.push = draftChannels.push;
    }

    if (draftChannels.line !== current.channels.line) {
      channels.line = draftChannels.line;
    }

    if (Object.keys(channels).length > 0) {
      patch.channels = channels;
    }

    const currentStart = current.quiet_hours?.start ?? '';
    const currentEnd = current.quiet_hours?.end ?? '';
    const draftStart = this.draftQuietStart().trim();
    const draftEnd = this.draftQuietEnd().trim();

    if (draftStart !== currentStart || draftEnd !== currentEnd) {
      if ((draftStart === '') !== (draftEnd === '')) {
        this.formError.set('กรุณากรอกเวลาเริ่มและสิ้นสุดให้ครบ');
        return null;
      }

      patch.quiet_hours =
        draftStart === '' && draftEnd === '' ? null : { start: draftStart, end: draftEnd };
    }

    if (this.draftDigest() !== current.digest) {
      patch.digest = this.draftDigest();
    }

    if (this.draftLocale() !== current.locale) {
      patch.locale = this.draftLocale();
    }

    return patch;
  }

  private announce(message: string): void {
    this.announcement.set('');
    queueMicrotask(() => this.announcement.set(message));
  }
}
