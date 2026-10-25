import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { Router } from '@angular/router';
import { toUserMessage } from '../../core/api/problem-details';
import { formatThaiDate } from '../../core/health-record/health-record.format';
import {
  AdminKnowledgeSource,
  KnowledgeSourceStatus,
  reviewStatusLabel,
} from '../../core/knowledge/knowledge-admin.models';
import {
  KnowledgeAdminError,
  KnowledgeAdminService,
} from '../../core/knowledge/knowledge-admin.service';

type StatusFilter = 'all' | KnowledgeSourceStatus;

const SEARCH_DEBOUNCE_MS = 300;

interface StatusOption {
  readonly value: StatusFilter;
  readonly label: string;
}

/**
 * Platform-admin screen for reviewing the public knowledge corpus (CR-005-UI).
 *
 * The corpus is platform data, not a patient record: the API returns no source
 * body and this page never asks for one. Only title, publisher, fetch date,
 * review state and staleness are rendered. A 403 is an expected state when a
 * capability was revoked after the token was issued, so it gets a calm
 * explanation instead of an error banner.
 */
@Component({
  selector: 'app-knowledge-admin-page',
  templateUrl: './knowledge-admin.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class KnowledgeAdminPage {
  private readonly service = inject(KnowledgeAdminService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly isPlatformAdmin = this.service.isPlatformAdmin;
  protected readonly sources = this.service.sources;
  protected readonly total = this.service.total;
  protected readonly loading = this.service.loading;
  protected readonly error = this.service.error;
  protected readonly errorKind = this.service.errorKind;

  private readonly permissionLost = signal(false);

  protected readonly forbidden = computed(
    () => this.permissionLost() || this.errorKind() === 'forbidden',
  );

  protected readonly statusFilter = signal<StatusFilter>('all');
  protected readonly searchInput = signal('');
  protected readonly successMessage = signal<string | null>(null);

  protected readonly reviewTarget = signal<AdminKnowledgeSource | null>(null);
  protected readonly reviewer = signal('');
  protected readonly rebuild = signal(true);
  protected readonly reviewSubmitting = signal(false);
  protected readonly reviewerError = signal<string | null>(null);
  protected readonly reviewError = signal<string | null>(null);

  protected readonly statusOptions: readonly StatusOption[] = [
    { value: 'all', label: 'ทั้งหมด' },
    { value: 'unreviewed', label: 'ยังไม่ตรวจ' },
    { value: 'reviewed', label: 'ตรวจแล้ว' },
  ];

  private searchTerm = '';
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    if (!this.isPlatformAdmin()) {
      void this.router.navigateByUrl('/dashboard');
      return;
    }

    void this.load();

    this.destroyRef.onDestroy(() => {
      this.clearSearchTimer();
      this.service.reset();
    });
  }

  protected async load(): Promise<void> {
    const status = this.statusFilter();

    try {
      await this.service.list({
        status: status === 'all' ? null : status,
        q: this.searchTerm,
      });
    } catch {
      // The service exposes the typed failure through `error`/`errorKind`.
    }
  }

  protected retry(): void {
    this.successMessage.set(null);
    void this.load();
  }

  protected onStatusChange(event: Event): void {
    const value = (event.target as HTMLSelectElement | null)?.value ?? 'all';

    this.statusFilter.set(value === 'reviewed' || value === 'unreviewed' ? value : 'all');
    this.successMessage.set(null);
    void this.load();
  }

  /**
   * The term is applied once, 300 ms after the last keystroke. Repeated input
   * replaces the pending timer so a five-letter query issues one request, not
   * five.
   */
  protected onSearchInput(event: Event): void {
    const value = (event.target as HTMLInputElement | null)?.value ?? '';

    this.searchInput.set(value);
    this.clearSearchTimer();

    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      this.searchTerm = value.trim();
      this.successMessage.set(null);
      void this.load();
    }, SEARCH_DEBOUNCE_MS);
  }

  protected statusLabel(status: string): string {
    return reviewStatusLabel(status);
  }

  protected formatFetched(value: string): string {
    return formatThaiDate(value);
  }

  protected openReview(source: AdminKnowledgeSource): void {
    this.reviewTarget.set(source);
    this.reviewer.set('');
    this.rebuild.set(true);
    this.reviewerError.set(null);
    this.reviewError.set(null);
    this.successMessage.set(null);
  }

  protected closeReview(): void {
    if (this.reviewSubmitting()) {
      return;
    }

    this.reviewTarget.set(null);
    this.reviewerError.set(null);
    this.reviewError.set(null);
  }

  protected setReviewer(event: Event): void {
    this.reviewer.set((event.target as HTMLInputElement | null)?.value ?? '');
    this.reviewerError.set(null);
  }

  protected setRebuild(event: Event): void {
    this.rebuild.set((event.target as HTMLInputElement | null)?.checked ?? false);
  }

  protected async submitReview(event: Event): Promise<void> {
    event.preventDefault();

    const target = this.reviewTarget();

    if (target === null || this.reviewSubmitting()) {
      return;
    }

    this.reviewerError.set(null);
    this.reviewError.set(null);

    const reviewer = this.reviewer().trim();

    if (reviewer.length < 3) {
      this.reviewerError.set('กรุณากรอกชื่อผู้ตรวจทานอย่างน้อย 3 ตัวอักษร');
      return;
    }

    this.reviewSubmitting.set(true);

    try {
      const updated = await this.service.review(target.id, {
        reviewer,
        build: this.rebuild(),
      });

      this.service.applyReviewed(updated);
      this.reviewTarget.set(null);
      this.successMessage.set(`บันทึกการตรวจทานแล้ว: ${updated.title}`);
    } catch (error) {
      if (error instanceof KnowledgeAdminError && error.kind === 'forbidden') {
        // The capability was revoked after the token was issued. A review POST
        // gets the same calm explanation as a denied list request.
        this.permissionLost.set(true);
        this.reviewTarget.set(null);
      } else if (error instanceof KnowledgeAdminError && error.kind === 'validation') {
        const fieldMessage = error.fieldErrors['reviewer'] ?? error.fieldErrors['source'] ?? null;

        if (fieldMessage !== null) {
          this.reviewerError.set(fieldMessage);
        } else {
          this.reviewError.set(error.message);
        }
      } else {
        this.reviewError.set(
          error instanceof KnowledgeAdminError ? error.message : toUserMessage(error),
        );
      }
    } finally {
      this.reviewSubmitting.set(false);
    }
  }

  private clearSearchTimer(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  }
}
