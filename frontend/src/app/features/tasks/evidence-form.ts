import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import {
  EVIDENCE_METRICS,
  EvidenceDraft,
  EvidenceType,
  TaskEvidence,
  buildEvidenceBody,
  isEvidenceType,
  validateEvidenceDraft,
} from '../../core/caretask/caretask.models';
import { CareTaskService } from '../../core/caretask/caretask.service';
import { DocumentService, DocumentUploadError } from '../../core/documents/document.service';

interface AttachedPhoto {
  readonly documentId: string;
  readonly name: string;
}

/**
 * Adds `value`, `note` or `photo` evidence to a clinical task.
 *
 * Photo evidence is a two-step operation on purpose: the file is uploaded to
 * the private document store first and only a successful upload yields the
 * `document_id` the evidence row needs. A failed upload therefore never
 * produces a dangling evidence row.
 *
 * The signed download URL is requested only when "ดูไฟล์" is clicked, opened
 * immediately and never stored in a signal or rendered into the DOM.
 */
@Component({
  selector: 'app-evidence-form',
  imports: [ReactiveFormsModule],
  templateUrl: './evidence-form.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EvidenceForm {
  private readonly careTasks = inject(CareTaskService);
  private readonly documents = inject(DocumentService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly taskId = input.required<string>();
  readonly profileId = input.required<string>();
  readonly version = input(0);

  readonly saved = output<TaskEvidence>();
  readonly reloadRequested = output<void>();

  protected readonly form = this.formBuilder.group({
    type: this.formBuilder.control('value'),
    metric: [EVIDENCE_METRICS[0]?.value ?? ''],
    value: [''],
    note: [''],
  });

  // `capture` is only a hint: desktop browsers ignore it, so the picker still
  // offers an existing photo or PDF.
  protected readonly captureHint =
    typeof navigator !== 'undefined' && navigator.maxTouchPoints > 0 ? 'environment' : null;

  protected readonly photoInput = viewChild<ElementRef<HTMLInputElement>>('photoInput');

  protected readonly selectedType = signal<EvidenceType>('value');
  protected readonly selectedMetric = signal(EVIDENCE_METRICS[0]?.value ?? '');
  protected readonly fieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly submitting = signal(false);

  protected readonly photoFile = signal<File | null>(null);
  protected readonly photoError = signal<string | null>(null);
  protected readonly uploadProgress = signal<number | null>(null);
  protected readonly uploading = signal(false);
  protected readonly attachedPhoto = signal<AttachedPhoto | null>(null);
  protected readonly openingFile = signal(false);
  protected readonly fileError = signal<string | null>(null);

  protected readonly metricOptions = EVIDENCE_METRICS;
  protected readonly typeOptions: ReadonlyArray<{ value: EvidenceType; label: string }> = [
    { value: 'value', label: 'ค่าที่วัด' },
    { value: 'note', label: 'บันทึกข้อความ' },
    { value: 'photo', label: 'ภาพถ่ายหรือไฟล์ PDF' },
  ];

  protected readonly selectedUnit = computed(
    () => EVIDENCE_METRICS.find((option) => option.value === this.selectedMetric())?.unit ?? '',
  );

  protected readonly selectedPhotoName = computed(() => {
    const file = this.photoFile();

    return file === null ? null : file.name;
  });

  protected errorFor(field: string): string | null {
    return this.fieldErrors()[field] ?? null;
  }

  protected changeType(event: Event): void {
    const target = event.target;

    if (target instanceof HTMLSelectElement && isEvidenceType(target.value)) {
      this.selectedType.set(target.value);
      this.fieldErrors.set({});
      this.formError.set(null);
      this.photoError.set(null);
    }
  }

  protected changeMetric(event: Event): void {
    const target = event.target;

    if (target instanceof HTMLSelectElement) {
      this.selectedMetric.set(target.value);
    }
  }

  protected choosePhoto(event: Event): void {
    const target = event.target;

    if (!(target instanceof HTMLInputElement)) {
      return;
    }

    const file = target.files !== null && target.files.length > 0 ? target.files.item(0) : null;

    this.photoFile.set(file);
    this.photoError.set(null);
    this.fileError.set(null);
    this.attachedPhoto.set(null);
  }

  /**
   * Issues a fresh link for the attached document and opens it in a new tab.
   * The URL lives only inside this call: no signal, no `href`, no cache.
   */
  protected async openAttachedFile(): Promise<void> {
    const attached = this.attachedPhoto();

    if (attached === null || this.openingFile()) {
      return;
    }

    this.openingFile.set(true);
    this.fileError.set(null);

    try {
      const signed = await this.documents.signedUrl(attached.documentId);
      const opened = window.open(signed.url, '_blank');

      if (opened !== null) {
        opened.opener = null;
      }
    } catch (error) {
      this.fileError.set(toUserMessage(error));
    } finally {
      this.openingFile.set(false);
    }
  }

  protected async submit(): Promise<void> {
    if (this.submitting() || this.uploading()) {
      return;
    }

    this.fieldErrors.set({});
    this.formError.set(null);
    this.photoError.set(null);
    this.conflict.set(false);

    const raw = this.form.getRawValue();
    const type = isEvidenceType(raw.type) ? raw.type : 'note';

    if (type === 'photo') {
      await this.submitPhoto();
      return;
    }

    const metric = EVIDENCE_METRICS.find((option) => option.value === raw.metric);
    const draft: EvidenceDraft = {
      type,
      metric: type === 'value' ? raw.metric : '',
      value: type === 'value' ? raw.value : null,
      unit: type === 'value' ? (metric?.unit ?? '') : '',
      note: type === 'note' ? raw.note : '',
    };
    const clientErrors = validateEvidenceDraft(draft);

    if (Object.keys(clientErrors).length > 0) {
      this.fieldErrors.set(clientErrors);
      return;
    }

    this.submitting.set(true);

    try {
      const created = await this.careTasks.addEvidence(
        this.taskId(),
        buildEvidenceBody(draft),
        this.version(),
      );

      this.form.controls.value.setValue('');
      this.form.controls.note.setValue('');
      this.saved.emit(created);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        return;
      }

      const serverErrors = readFieldErrors(error);

      if (Object.keys(serverErrors).length > 0) {
        this.fieldErrors.set(serverErrors);
      } else {
        this.formError.set(toUserMessage(error));
      }
    } finally {
      this.submitting.set(false);
    }
  }

  /**
   * Upload first, then create the evidence row. Nothing is attached when the
   * upload fails, so a missing document can never be referenced.
   */
  private async submitPhoto(): Promise<void> {
    const file = this.photoFile();

    if (file === null) {
      this.photoError.set('กรุณาเลือกไฟล์ภาพถ่ายหรือไฟล์ PDF');
      return;
    }

    this.submitting.set(true);
    this.uploading.set(true);
    this.uploadProgress.set(0);

    try {
      const document = await this.documents.upload(this.profileId(), file, 'photo', (percent) =>
        this.uploadProgress.set(percent),
      );

      this.uploadProgress.set(100);

      const created = await this.careTasks.addEvidence(
        this.taskId(),
        { type: 'photo', document_id: document.id },
        this.version(),
      );

      this.attachedPhoto.set({ documentId: document.id, name: file.name });
      this.photoFile.set(null);
      this.photoError.set(null);

      const input = this.photoInput();

      if (input !== undefined) {
        input.nativeElement.value = '';
      }

      this.saved.emit(created);
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        return;
      }

      if (error instanceof DocumentUploadError) {
        this.photoError.set(error.message);
        return;
      }

      // The upload succeeded but the evidence row did not; nothing is
      // attached and the server's field sentence is shown under the picker.
      const serverErrors = readFieldErrors(error);
      const message =
        serverErrors['document_id'] ??
        serverErrors['evidence'] ??
        serverErrors['type'] ??
        toUserMessage(error);

      this.photoError.set(message);
    } finally {
      this.uploading.set(false);
      this.uploadProgress.set(null);
      this.submitting.set(false);
    }
  }
}
