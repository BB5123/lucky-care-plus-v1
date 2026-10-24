import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { toUserMessage } from '../../core/api/problem-details';
import { HealthReportPayload } from '../../core/reports/report.models';
import { BriefService } from '../../core/reports/brief.service';

/**
 * `/briefs/:token` — the read-only page a clinic opens from the share link.
 *
 * It has no auth guard and no app shell: the token is the whole capability.
 * The page shows only what the frozen payload contains (per-section grants were
 * applied when the brief was created) and offers print. A 404/410 is rendered
 * as "expired" without revealing whether the token ever existed.
 */
@Component({
  selector: 'app-brief-page',
  templateUrl: './brief.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BriefPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly service = inject(BriefService);

  protected readonly loading = signal(true);
  protected readonly payload = signal<HealthReportPayload | null>(null);
  protected readonly error = signal<string | null>(null);

  async ngOnInit(): Promise<void> {
    const token = this.route.snapshot.paramMap.get('token') ?? '';

    if (token === '') {
      this.error.set('ลิงก์นี้ไม่ถูกต้องหรือหมดอายุแล้ว');
      this.loading.set(false);
      return;
    }

    try {
      this.payload.set(await this.service.fetch(token));
    } catch (error) {
      this.error.set(this.messageFor(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected print(): void {
    window.print();
  }

  private messageFor(error: unknown): string {
    if (error instanceof HttpErrorResponse && (error.status === 404 || error.status === 410)) {
      return 'ลิงก์นี้ไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอลิงก์ใหม่จากเจ้าของข้อมูล';
    }

    return toUserMessage(error);
  }
}
