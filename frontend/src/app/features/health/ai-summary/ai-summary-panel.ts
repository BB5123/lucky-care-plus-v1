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
import { AiSummary } from '../../../core/ai/ai-summary.models';
import { AiSummaryService } from '../../../core/ai/ai-summary.service';

function problemCode(error: unknown): string | null {
  if (!(error instanceof HttpErrorResponse) || typeof error.error !== 'object' || error.error === null) {
    return null;
  }

  const code = (error.error as Record<string, unknown>)['code'];

  return typeof code === 'string' ? code : null;
}

/**
 * "Summarise with AI" panel for one health profile (G4-UI).
 *
 * The summary is a draft from the gateway: the panel shows the server-authored
 * disclaimer, how many records were used and the remaining daily quota. It is
 * deliberately click-to-generate (AIG-004: the user sees what is sent before
 * the first call) and maps each gateway error code to a Thai sentence.
 */
@Component({
  selector: 'app-ai-summary-panel',
  templateUrl: './ai-summary-panel.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AiSummaryPanel {
  readonly profileId = input.required<string>();

  private readonly service = inject(AiSummaryService);

  protected readonly loading = signal(false);
  protected readonly summary = signal<AiSummary | null>(null);
  protected readonly error = signal<string | null>(null);

  private requestId = 0;

  constructor() {
    effect(() => {
      this.profileId();

      untracked(() => {
        this.requestId++;
        this.loading.set(false);
        this.summary.set(null);
        this.error.set(null);
      });
    });
  }

  protected async generate(): Promise<void> {
    if (this.loading()) {
      return;
    }

    const request = ++this.requestId;

    this.loading.set(true);
    this.summary.set(null);
    this.error.set(null);

    try {
      const result = await this.service.summarize(this.profileId());

      if (request === this.requestId) {
        this.summary.set(result);
      }
    } catch (error) {
      if (request === this.requestId) {
        this.error.set(this.messageFor(error));
      }
    } finally {
      if (request === this.requestId) {
        this.loading.set(false);
      }
    }
  }

  private messageFor(error: unknown): string {
    const code = problemCode(error);

    if (code === 'ai_disabled') {
      return 'ฟีเจอร์ AI ยังไม่เปิดใช้งานในระบบนี้';
    }

    if (code === 'no_consent') {
      return 'ต้องได้รับความยินยอมสำหรับการประมวลผลด้วย AI ก่อนใช้ฟีเจอร์นี้';
    }

    if (code === 'ai_quota_exceeded') {
      return 'คุณใช้ AI ครบจำนวนที่กำหนดในวันนี้แล้ว กรุณาลองใหม่พรุ่งนี้';
    }

    if (error instanceof HttpErrorResponse && error.status === 403) {
      return 'คุณยังไม่มีสิทธิ์ใช้ AI กับข้อมูลนี้';
    }

    if (error instanceof HttpErrorResponse && error.status === 503) {
      return 'ระบบ AI ไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ภายหลัง';
    }

    return toUserMessage(error);
  }
}
