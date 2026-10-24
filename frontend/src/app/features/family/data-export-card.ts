import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import {
  DataExportError,
  DataExportService,
  DataExportSnapshot,
  DataExportStatusValue,
  DEFAULT_EXPORT_RATE_LIMIT_SECONDS,
} from '../../core/export/data-export.service';

type CardStatus = 'idle' | DataExportStatusValue;

/** How often a queued/building export is asked for its status while open. */
export const DATA_EXPORT_POLL_INTERVAL_MS = 4000;

function normalizeStatus(status: string): DataExportStatusValue {
  switch (status) {
    case 'queued':
    case 'building':
    case 'ready':
    case 'failed':
    case 'expired':
    case 'revoked':
      return status;
    default:
      return 'failed';
  }
}

/**
 * "ขอสำเนาข้อมูลของฉัน" card for one health profile.
 *
 * The card only shows lifecycle metadata: byte size and expiry. It never
 * renders a health value or an object key, because neither is present in the
 * status resource. Polling runs only while the component is alive and stops as
 * soon as the export reaches a terminal state.
 */
@Component({
  selector: 'app-data-export-card',
  templateUrl: './data-export-card.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DataExportCard {
  readonly profileId = input.required<string>();

  private readonly exports = inject(DataExportService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly status = signal<CardStatus>('idle');
  protected readonly exportId = signal<string | null>(null);
  protected readonly byteSize = signal<number | null>(null);
  protected readonly expiresAt = signal<string | null>(null);
  protected readonly requesting = signal(false);
  protected readonly refreshing = signal(false);
  protected readonly downloading = signal(false);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly permissionDenied = signal(false);
  protected readonly rateLimitedSeconds = signal<number | null>(null);

  protected readonly isPending = computed(
    () => this.status() === 'queued' || this.status() === 'building',
  );
  protected readonly isReady = computed(() => this.status() === 'ready');
  protected readonly requestLabel = computed(() =>
    this.status() === 'expired' || this.status() === 'failed' ? 'ขอข้อมูลใหม่' : 'ขอข้อมูล',
  );
  protected readonly statusMessage = computed(() =>
    this.status() === 'queued'
      ? 'คำขอของคุณอยู่ในคิว กำลังจัดเตรียมสำเนาข้อมูล...'
      : 'กำลังจัดเตรียมสำเนาข้อมูล...',
  );

  private pollTimer: ReturnType<typeof setTimeout> | null = null;
  private requestToken = 0;
  private lastProfileId: string | null = null;

  constructor() {
    effect(() => {
      const profileId = this.profileId();

      if (profileId === this.lastProfileId) {
        return;
      }

      this.lastProfileId = profileId;

      untracked(() => this.resetState());
    });

    this.destroyRef.onDestroy(() => {
      this.requestToken += 1;
      this.stopPolling();
    });
  }

  protected async requestExport(): Promise<void> {
    if (this.requesting() || this.isPending() || this.isReady()) {
      return;
    }

    const token = ++this.requestToken;

    this.stopPolling();
    this.requesting.set(true);
    this.errorMessage.set(null);
    this.permissionDenied.set(false);
    this.rateLimitedSeconds.set(null);
    this.exportId.set(null);
    this.byteSize.set(null);
    this.expiresAt.set(null);
    this.status.set('idle');

    try {
      const result = await this.exports.request(this.profileId());

      if (token !== this.requestToken) {
        return;
      }

      this.exportId.set(result.id);
      this.status.set(normalizeStatus(result.status));

      if (this.isPending()) {
        this.schedulePoll();
      }
    } catch (error) {
      if (token === this.requestToken) {
        this.applyFailure(error);
      }
    } finally {
      if (token === this.requestToken) {
        this.requesting.set(false);
      }
    }
  }

  protected async refreshStatus(): Promise<void> {
    if (this.refreshing()) {
      return;
    }

    await this.loadStatus();
  }

  protected async download(): Promise<void> {
    const id = this.exportId();

    if (id === null || this.downloading()) {
      return;
    }

    this.downloading.set(true);
    this.errorMessage.set(null);

    try {
      const link = await this.exports.link(this.profileId(), id);
      // A signed export link is a short-lived capability. Open it right away
      // and never keep it in a signal, storage or the template.
      const opened = window.open(link.url, '_blank');

      if (opened !== null) {
        opened.opener = null;
      }
    } catch (error) {
      this.applyFailure(error);
    } finally {
      this.downloading.set(false);
    }
  }

  protected retryWaitLabel(seconds: number): string {
    const totalMinutes = Math.max(1, Math.ceil(seconds / 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours > 0 && minutes > 0) {
      return `${hours} ชั่วโมง ${minutes} นาที`;
    }

    if (hours > 0) {
      return `${hours} ชั่วโมง`;
    }

    return `${minutes} นาที`;
  }

  protected sizeLabel(): string {
    const bytes = this.byteSize();

    if (bytes === null) {
      return 'ไม่ทราบขนาด';
    }

    if (bytes < 1024) {
      return `${bytes} ไบต์`;
    }

    const kilobytes = bytes / 1024;

    if (kilobytes < 1024) {
      return `${new Intl.NumberFormat('th-TH', { maximumFractionDigits: 1 }).format(kilobytes)} KB`;
    }

    return `${new Intl.NumberFormat('th-TH', { maximumFractionDigits: 1 }).format(
      kilobytes / 1024,
    )} MB`;
  }

  protected expiryLabel(): string {
    const value = this.expiresAt();

    if (value === null) {
      return 'ไม่ทราบ';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return 'ไม่ทราบ';
    }

    return new Intl.DateTimeFormat('th-TH', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date);
  }

  private async loadStatus(): Promise<void> {
    const id = this.exportId();

    if (id === null) {
      return;
    }

    const token = this.requestToken;

    this.stopPolling();
    this.refreshing.set(true);

    try {
      const snapshot = await this.exports.status(this.profileId(), id);

      if (token === this.requestToken) {
        this.applySnapshot(snapshot);
      }
    } catch (error) {
      if (token === this.requestToken) {
        this.applyFailure(error);
      }
    } finally {
      if (token === this.requestToken) {
        this.refreshing.set(false);

        if (this.shouldKeepPolling()) {
          this.schedulePoll();
        }
      }
    }
  }

  private schedulePoll(): void {
    this.stopPolling();

    if (!this.shouldKeepPolling()) {
      return;
    }

    this.pollTimer = setTimeout(() => {
      this.pollTimer = null;
      void this.loadStatus();
    }, DATA_EXPORT_POLL_INTERVAL_MS);
  }

  private stopPolling(): void {
    if (this.pollTimer !== null) {
      clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private shouldKeepPolling(): boolean {
    return (
      this.exportId() !== null &&
      this.isPending() &&
      this.errorMessage() === null &&
      !this.permissionDenied()
    );
  }

  private applySnapshot(snapshot: DataExportSnapshot): void {
    const status = normalizeStatus(snapshot.status);

    this.exportId.set(snapshot.id);
    this.status.set(status);
    this.byteSize.set(status === 'ready' ? snapshot.byte_size : null);
    this.expiresAt.set(status === 'ready' ? snapshot.expires_at : null);
    this.permissionDenied.set(status === 'revoked');
    this.errorMessage.set(null);
  }

  private applyFailure(error: unknown): void {
    const failure = error instanceof DataExportError ? error : null;
    const code = failure?.code ?? 'unknown';

    this.stopPolling();

    if (code === 'rate_limited') {
      this.status.set('idle');
      this.exportId.set(null);
      this.byteSize.set(null);
      this.expiresAt.set(null);
      this.permissionDenied.set(false);
      this.errorMessage.set(null);
      this.rateLimitedSeconds.set(failure?.retryAfterSeconds ?? DEFAULT_EXPORT_RATE_LIMIT_SECONDS);
      return;
    }

    if (code === 'not_permitted') {
      this.status.set('idle');
      this.exportId.set(null);
      this.byteSize.set(null);
      this.expiresAt.set(null);
      this.errorMessage.set(null);
      this.rateLimitedSeconds.set(null);
      this.permissionDenied.set(true);
      return;
    }

    if (code === 'expired') {
      this.status.set('expired');
      this.exportId.set(null);
      this.byteSize.set(null);
      this.expiresAt.set(null);
      this.permissionDenied.set(false);
      this.errorMessage.set(null);
      this.rateLimitedSeconds.set(null);
      return;
    }

    if (code === 'not_ready') {
      this.status.set('building');
      this.errorMessage.set(null);
      this.schedulePoll();
      return;
    }

    this.errorMessage.set(failure?.message ?? 'ไม่สามารถดำเนินการกับสำเนาข้อมูลได้ในขณะนี้');
  }

  private resetState(): void {
    this.requestToken += 1;
    this.stopPolling();
    this.status.set('idle');
    this.exportId.set(null);
    this.byteSize.set(null);
    this.expiresAt.set(null);
    this.requesting.set(false);
    this.refreshing.set(false);
    this.downloading.set(false);
    this.errorMessage.set(null);
    this.permissionDenied.set(false);
    this.rateLimitedSeconds.set(null);
  }
}
