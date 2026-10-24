import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { httpStatus, toUserMessage } from '../../core/api/problem-details';
import { AuthService } from '../../core/auth/auth.service';
import {
  CareTask,
  CareTaskFull,
  CareTaskOutcome,
  EVIDENCE_METRICS,
  TaskAction,
  TaskAssignment,
  TaskEvidence,
  actionRequiresReason,
  availableActions,
  completionOutcomes,
  evidencePolicyLabel,
  missingEvidenceMessage,
  taskActionLabel,
  taskOutcomeLabel,
  taskPriorityLabel,
  taskStatusLabel,
  taskTierLabel,
  taskTypeLabel,
  verificationLabel,
} from '../../core/caretask/caretask.models';
import { CareTaskService } from '../../core/caretask/caretask.service';
import {
  formatHealthNumber,
  formatThaiDateTime,
} from '../../core/health-record/health-record.format';
import { AssignForm } from './assign-form';
import { EvidenceForm } from './evidence-form';

const ROLE_LABELS: Readonly<Record<string, string>> = {
  primary: 'ผู้รับผิดชอบหลัก',
  assistant: 'ผู้ช่วย',
  verifier: 'ผู้ตรวจรับ',
};

function normalizeLocalDateTime(value: string): string {
  const trimmed = value.trim();

  if (trimmed === '') {
    return '';
  }

  return trimmed.length === 16 ? `${trimmed}:00` : trimmed;
}

/**
 * Care-task detail and transitions.
 *
 * The action buttons only *offer* what the state machine allows; every click
 * sends the version the screen was rendered from and lets the server decide.
 * A 409 renders the server's Thai domain sentence, while a 412 replaces the
 * screen with "ข้อมูลถูกแก้ไขโดยคนอื่น" and a reload action.
 */
@Component({
  selector: 'app-task-detail',
  imports: [RouterLink, ReactiveFormsModule, AssignForm, EvidenceForm],
  templateUrl: './task-detail.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TaskDetail {
  private readonly careTasks = inject(CareTaskService);
  private readonly auth = inject(AuthService);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  readonly taskId = input.required<string>();

  protected readonly task = this.careTasks.detail;
  protected readonly loading = this.careTasks.detailLoading;
  protected readonly error = this.careTasks.detailError;
  protected readonly errorStatus = this.careTasks.detailErrorStatus;

  protected readonly evidence = this.careTasks.evidence;
  protected readonly evidenceLoading = this.careTasks.evidenceLoading;
  protected readonly evidenceError = this.careTasks.evidenceError;

  protected readonly completedBy = this.careTasks.completedBy;

  protected readonly pendingAction = signal<TaskAction | null>(null);
  protected readonly assignOpen = signal(false);
  protected readonly submitting = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly conflict = signal(false);
  protected readonly announcement = signal<string | null>(null);
  protected readonly reasonError = signal<string | null>(null);
  protected readonly reviewAtError = signal<string | null>(null);

  /**
   * Document id attached in this session, used together with the server's
   * `has_photo_evidence` flag to gate clinical completion (F-06).
   */
  protected readonly attachedDocumentId = signal<string | null>(null);

  protected readonly reasonForm = this.formBuilder.group({
    reason: [''],
    review_at: [''],
  });

  protected readonly completeForm = this.formBuilder.group({
    outcome: this.formBuilder.control('done'),
  });

  protected readonly currentUserId = computed(() => this.auth.user()?.id ?? null);

  protected readonly full = computed<CareTaskFull | null>(() => {
    const task = this.task();

    return task !== null && task.kind === 'full' ? task : null;
  });

  protected readonly actions = computed<readonly TaskAction[]>(() => {
    const task = this.task();

    if (task === null) {
      return [];
    }

    return availableActions(task, {
      currentUserId: this.currentUserId(),
      completedByUserId: this.completedBy(),
    });
  });

  protected readonly missingEvidence = computed(() => {
    const task = this.task();

    return task === null
      ? null
      : missingEvidenceMessage(task, this.evidence(), this.attachedDocumentId());
  });

  protected readonly outcomes = computed<readonly CareTaskOutcome[]>(() => {
    const task = this.full();

    return task === null ? ['done'] : completionOutcomes(task.tier);
  });

  /**
   * Assignment is not a generic transition button: it needs the workspace
   * member picker, so it is offered only where the tier has an assignment
   * step. The server still checks `canManage` and single-primary.
   */
  protected readonly canAssign = computed(() => {
    const task = this.full();

    return (
      task !== null &&
      (task.tier === 'delegated' || task.tier === 'clinical') &&
      (task.status === 'open' || task.status === 'assigned')
    );
  });

  protected readonly hasActionArea = computed(() => this.actions().length > 0 || this.canAssign());

  constructor() {
    effect(() => {
      const taskId = this.taskId();

      untracked(() => {
        void this.careTasks.loadDetail(taskId);
      });
    });
  }

  protected actionLabel(action: TaskAction): string {
    return taskActionLabel(action);
  }

  protected dangerAction(action: TaskAction): boolean {
    return ['decline', 'reject', 'cancel', 'block'].includes(action);
  }

  protected chooseAction(action: TaskAction): void {
    this.formError.set(null);
    this.conflict.set(false);
    this.reasonError.set(null);
    this.reviewAtError.set(null);

    if (action === 'complete') {
      this.completeForm.controls.outcome.setValue(this.outcomes()[0] ?? 'done');
    } else {
      this.reasonForm.reset({ reason: '', review_at: '' });
    }

    if (action === 'complete' || action === 'block' || actionRequiresReason(action)) {
      this.pendingAction.set(action);
      return;
    }

    this.pendingAction.set(null);
    void this.runAction(action);
  }

  protected closePanel(): void {
    this.pendingAction.set(null);
    this.reasonError.set(null);
    this.reviewAtError.set(null);
    this.formError.set(null);
    this.conflict.set(false);
  }

  protected openAssign(): void {
    this.formError.set(null);
    this.conflict.set(false);
    this.assignOpen.set(true);
  }

  protected closeAssign(): void {
    this.assignOpen.set(false);
  }

  protected async submitAction(): Promise<void> {
    const action = this.pendingAction();

    if (action === null || this.submitting()) {
      return;
    }

    this.formError.set(null);
    this.conflict.set(false);

    if (action === 'complete') {
      await this.runAction(action, {
        outcome: this.completeForm.getRawValue().outcome,
      });
      return;
    }

    const raw = this.reasonForm.getRawValue();
    const reason = raw.reason.trim();

    if (reason === '') {
      this.reasonError.set('กรุณาระบุเหตุผล');
      return;
    }

    if (reason.length > 1000) {
      this.reasonError.set('เหตุผลต้องไม่เกิน 1,000 ตัวอักษร');
      return;
    }

    if (action === 'reopen' && reason.length < 5) {
      this.reasonError.set('เหตุผลต้องมีอย่างน้อย 5 ตัวอักษร');
      return;
    }

    if (action === 'block') {
      const reviewAt = normalizeLocalDateTime(raw.review_at);

      if (reviewAt === '' || Number.isNaN(Date.parse(reviewAt))) {
        this.reviewAtError.set('กรุณาระบุเวลาที่จะกลับมาทบทวน');
        return;
      }

      await this.runAction(action, { blocked_reason: reason, review_at: reviewAt });
      return;
    }

    await this.runAction(action, { reason });
  }

  protected async runAction(
    action: TaskAction,
    body: Readonly<Record<string, unknown>> = {},
  ): Promise<void> {
    const task = this.task();

    if (task === null || this.submitting()) {
      return;
    }

    this.submitting.set(true);

    try {
      await this.careTasks.act(task.id, task.version, action, body);
      this.pendingAction.set(null);
      this.assignOpen.set(false);
      this.announce('ดําเนินการแล้ว');
    } catch (error) {
      if (httpStatus(error) === 412) {
        this.conflict.set(true);
        return;
      }

      // 409 is the domain rule; `toUserMessage` carries the server's safe Thai
      // sentence. It is never replaced by a generic toast.
      this.formError.set(toUserMessage(error));
    } finally {
      this.submitting.set(false);
    }
  }

  protected onAssigned(): void {
    this.assignOpen.set(false);
    this.announce('มอบหมายงานแล้ว');
  }

  protected onEvidenceSaved(evidence: TaskEvidence): void {
    if (evidence.type === 'photo' && evidence.document_id !== null) {
      this.attachedDocumentId.set(evidence.document_id);
    }

    this.announce('เพิ่มหลักฐานแล้ว');
  }

  protected reload(): void {
    this.pendingAction.set(null);
    this.assignOpen.set(false);
    this.conflict.set(false);
    this.formError.set(null);
    this.announcement.set(null);
    void this.careTasks.loadDetail(this.taskId());
  }

  protected dueLabel(task: CareTask): string {
    return formatThaiDateTime(task.due_at);
  }

  protected dateTimeLabel(value: string | null): string {
    return formatThaiDateTime(value);
  }

  protected numberLabel(value: number | null): string {
    return formatHealthNumber(value);
  }

  protected typeLabel(task: CareTask): string {
    return taskTypeLabel(task.type);
  }

  protected statusLabel(task: CareTask): string {
    return taskStatusLabel(task.status);
  }

  protected priorityLabel(task: CareTask): string {
    return taskPriorityLabel(task.priority);
  }

  protected tierLabel(task: CareTaskFull): string {
    return taskTierLabel(task.tier);
  }

  protected outcomeLabel(outcome: string | null): string {
    return taskOutcomeLabel(outcome);
  }

  protected verificationStateLabel(verification: string): string {
    return verificationLabel(verification);
  }

  protected policyLabel(policy: string): string {
    return evidencePolicyLabel(policy);
  }

  protected assignmentRoleLabel(assignment: TaskAssignment): string {
    return ROLE_LABELS[assignment.role] ?? assignment.role;
  }

  protected assignmentStateLabel(assignment: TaskAssignment): string {
    if (assignment.released_at !== null) {
      return 'สิ้นสุดแล้ว';
    }

    if (assignment.declined_at !== null) {
      return 'ปฏิเสธงาน';
    }

    if (assignment.accepted_at !== null) {
      return 'รับงานแล้ว';
    }

    return 'รอตอบรับ';
  }

  protected evidenceTypeLabel(type: string): string {
    switch (type) {
      case 'value':
        return 'ค่าที่วัด';
      case 'photo':
        return 'ภาพถ่าย';
      default:
        return 'บันทึก';
    }
  }

  protected evidenceSummary(item: TaskEvidence): string {
    if (item.type === 'value') {
      const metric =
        EVIDENCE_METRICS.find((option) => option.value === item.metric)?.label ??
        item.metric ??
        'ค่าที่วัด';
      const unit = item.unit === null || item.unit === '' ? '' : ` ${item.unit}`;

      return `${metric} ${this.numberLabel(item.value)}${unit}`.trim();
    }

    if (item.type === 'photo') {
      return 'ภาพถ่ายที่แนบไว้';
    }

    return item.note ?? 'ไม่มีข้อความ';
  }

  protected outcomeOptionLabel(outcome: CareTaskOutcome): string {
    return taskOutcomeLabel(outcome);
  }

  private announce(message: string): void {
    this.announcement.set('');
    queueMicrotask(() => this.announcement.set(message));
  }
}
