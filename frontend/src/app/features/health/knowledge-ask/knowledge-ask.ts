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
import { KnowledgeAnswer } from '../../../core/knowledge/knowledge.models';
import { KnowledgeService } from '../../../core/knowledge/knowledge.service';

function problemCode(error: unknown): string | null {
  if (
    !(error instanceof HttpErrorResponse) ||
    typeof error.error !== 'object' ||
    error.error === null
  ) {
    return null;
  }

  const code = (error.error as Record<string, unknown>)['code'];

  return typeof code === 'string' ? code : null;
}

/**
 * "Ask a health question" panel backed by the government-source corpus
 * (TASK G4-KB / web UI).
 *
 * Answers are always cited; the panel renders the server disclaimer and the
 * source list with links. It is click-to-ask and maps each gateway error code
 * to a Thai sentence, including the "no corpus match" case where no provider
 * was called.
 */
@Component({
  selector: 'app-knowledge-ask',
  templateUrl: './knowledge-ask.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class KnowledgeAsk {
  readonly profileId = input.required<string>();

  private readonly service = inject(KnowledgeService);

  protected readonly question = signal('');
  protected readonly loading = signal(false);
  protected readonly answer = signal<KnowledgeAnswer | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly maxLength = 500;

  private requestId = 0;

  constructor() {
    effect(() => {
      this.profileId();

      untracked(() => {
        this.requestId++;
        this.loading.set(false);
        this.answer.set(null);
        this.error.set(null);
      });
    });
  }

  protected updateQuestion(event: Event): void {
    const value = (event.target as HTMLTextAreaElement).value;

    this.question.set(value.slice(0, this.maxLength));
  }

  protected async ask(): Promise<void> {
    const question = this.question().trim();

    if (question === '' || this.loading()) {
      return;
    }

    const request = ++this.requestId;

    this.loading.set(true);
    this.answer.set(null);
    this.error.set(null);

    try {
      const result = await this.service.ask(this.profileId(), question);

      if (request === this.requestId) {
        this.answer.set(result);
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

    if (code === 'knowledge_not_found') {
      return 'ไม่พบข้อมูลในฐานความรู้สำหรับคำถามนี้ ลองถามด้วยคำที่เฉพาะเจาะจงขึ้น หรือปรึกษาแพทย์';
    }

    if (code === 'ai_disabled') {
      return 'ฟีเจอร์ AI ยังไม่เปิดใช้งานในระบบนี้';
    }

    if (code === 'no_consent') {
      return 'ต้องได้รับความยินยอมสำหรับการประมวลผลด้วย AI ก่อนใช้ฟีเจอร์นี้';
    }

    if (code === 'ai_quota_exceeded') {
      return 'คุณใช้ AI ครบจำนวนที่กำหนดในวันนี้แล้ว กรุณาลองใหม่พรุ่งนี้';
    }

    if (code !== null && code.startsWith('refuse_')) {
      return toUserMessage(error);
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
