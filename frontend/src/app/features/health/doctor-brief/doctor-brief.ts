import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { toUserMessage } from '../../../core/api/problem-details';
import { HealthReport, ReportShare } from '../../../core/reports/report.models';
import { ReportService } from '../../../core/reports/report.service';

/**
 * Doctor Brief card (Phase 2.3 / web UI).
 *
 * Two explicit steps: create a frozen snapshot, then mint a 30-minute
 * capability link. Create and share are separate permissions (F-06 / AP-004);
 * the card never combines them into one click. The payload preview is a short
 * summary only — the doctor reads the shared brief.
 */
@Component({
  selector: 'app-doctor-brief',
  templateUrl: './doctor-brief.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DoctorBrief {
  readonly profileId = input.required<string>();

  private readonly service = inject(ReportService);

  protected readonly creating = signal(false);
  protected readonly report = signal<HealthReport | null>(null);
  protected readonly error = signal<string | null>(null);

  protected readonly sharing = signal(false);
  protected readonly share = signal<ReportShare | null>(null);
  protected readonly shareError = signal<string | null>(null);
  protected readonly copied = signal(false);

  constructor() {
    effect(() => {
      this.profileId();

      untracked(() => {
        this.creating.set(false);
        this.report.set(null);
        this.error.set(null);
        this.sharing.set(false);
        this.share.set(null);
        this.shareError.set(null);
        this.copied.set(false);
      });
    });
  }

  protected async create(): Promise<void> {
    if (this.creating()) {
      return;
    }

    this.creating.set(true);
    this.report.set(null);
    this.error.set(null);
    this.share.set(null);
    this.shareError.set(null);
    this.copied.set(false);

    try {
      this.report.set(await this.service.create(this.profileId()));
    } catch (error) {
      this.error.set(this.messageFor(error));
    } finally {
      this.creating.set(false);
    }
  }

  protected async createShare(): Promise<void> {
    const report = this.report();

    if (report === null || this.sharing()) {
      return;
    }

    this.sharing.set(true);
    this.share.set(null);
    this.shareError.set(null);
    this.copied.set(false);

    try {
      this.share.set(await this.service.share(this.profileId(), report.id));
    } catch (error) {
      this.shareError.set(this.messageFor(error));
    } finally {
      this.sharing.set(false);
    }
  }

  protected async copy(): Promise<void> {
    const url = this.share()?.url;

    if (url === undefined) {
      return;
    }

    await navigator.clipboard?.writeText(url);
    this.copied.set(true);
  }

  private messageFor(error: unknown): string {
    if (error instanceof HttpErrorResponse && error.status === 403) {
      return 'คุณยังไม่มีสิทธิ์สร้างหรือแชร์สรุปสำหรับแพทย์ — ต้องมีสิทธิ์ส่งออกและความยินยอมสำหรับการส่งออกข้อมูล';
    }

    return toUserMessage(error);
  }
}
