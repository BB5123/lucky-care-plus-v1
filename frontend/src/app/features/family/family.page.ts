import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule } from '@angular/forms';
import { httpStatus, readFieldErrors, toUserMessage } from '../../core/api/problem-details';
import { AuthService } from '../../core/auth/auth.service';
import { FamilyService } from '../../core/family/family.service';
import {
  INVITABLE_ROLE_DEFINITIONS,
  INVITABLE_ROLES,
  InvitableRole,
  ROLE_DEFINITIONS,
  WorkspaceMember,
  buildInviteLink,
  isValidEmail,
  roleLabel,
  statusLabel,
} from '../../core/family/member.models';
import { ProfilesService } from '../../core/profiles/profiles.service';
import { WorkspaceService } from '../../core/workspace/workspace.service';
import { DataExportCard } from './data-export-card';

interface InviteLinkView {
  readonly email: string;
  readonly role: string;
  readonly link: string;
}

/**
 * Family tab: members, role legend and invitations.
 *
 * The baseline lets Owner, Guardian and Workspace Admin read the member list;
 * Viewer and Caregiver are denied. A 403 therefore renders an explanation, not
 * an error banner. The invitation token is kept in a component signal only —
 * the service returns it once and the page never writes it to storage.
 */
@Component({
  selector: 'app-family-page',
  imports: [ReactiveFormsModule, DataExportCard],
  templateUrl: './family.page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FamilyPage {
  private readonly workspace = inject(WorkspaceService);
  private readonly family = inject(FamilyService);
  private readonly auth = inject(AuthService);
  private readonly profilesService = inject(ProfilesService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly formBuilder = inject(NonNullableFormBuilder);

  protected readonly roleDefinitions = ROLE_DEFINITIONS;
  protected readonly invitableRoles = INVITABLE_ROLE_DEFINITIONS;

  protected readonly hasWorkspace = this.workspace.hasWorkspace;
  protected readonly members = this.family.members;
  protected readonly loading = this.family.loading;
  protected readonly error = this.family.error;
  protected readonly errorStatus = this.family.errorStatus;

  /**
   * The export card is profile-scoped. The profile picker is authorized per
   * profile (`health_profile.read`) and prefers the actor's own profile, so
   * "ขอสำเนาข้อมูลของฉัน" addresses the same record the rest of the app opens.
   */
  protected readonly exportProfileId = computed(
    () => this.profilesService.currentProfile()?.id ?? null,
  );

  protected readonly pageLoading = signal(true);
  protected readonly pageError = signal<string | null>(null);
  protected readonly forbidden = computed(() => this.errorStatus() === 403);

  protected readonly inviteForm = this.formBuilder.group({
    email: [''],
    role: this.formBuilder.control<InvitableRole>('caregiver'),
  });
  protected readonly inviting = signal(false);
  protected readonly inviteResult = signal<InviteLinkView | null>(null);
  protected readonly inviteError = signal<string | null>(null);
  protected readonly inviteFieldErrors = signal<Readonly<Record<string, string>>>({});
  protected readonly copyMessage = signal<string | null>(null);

  protected readonly isSoleMember = computed(() => {
    const list = this.members();

    if (list.length === 0) {
      return true;
    }

    return list.length === 1 && this.isCurrentUser(list[0]);
  });

  private bootstrapped = false;
  private lastWorkspaceId: string | null = null;

  constructor() {
    effect(() => {
      const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

      if (!this.bootstrapped) {
        this.lastWorkspaceId = workspaceId;
        return;
      }

      if (workspaceId === this.lastWorkspaceId) {
        return;
      }

      this.lastWorkspaceId = workspaceId;

      untracked(() => {
        void this.initialize();
      });
    });

    this.destroyRef.onDestroy(() => {
      this.family.reset();
      this.inviteResult.set(null);
    });

    void this.initialize();
  }

  protected async initialize(): Promise<void> {
    this.pageLoading.set(true);
    this.pageError.set(null);
    this.family.reset();
    this.resetInviteState();

    try {
      await this.workspace.load();
    } catch (error) {
      this.pageError.set(toUserMessage(error));
      this.pageLoading.set(false);
      this.bootstrapped = true;
      return;
    }

    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    this.lastWorkspaceId = workspaceId;
    this.pageLoading.set(false);
    this.bootstrapped = true;

    if (workspaceId !== null) {
      await this.family.loadMembers(workspaceId);

      // The export card needs the same per-profile decision as the rest of
      // the app. A 403/404 leaves the list empty and simply hides the card;
      // it must not turn the whole family page into an error state.
      void this.profilesService.load();
    }
  }

  protected retry(): void {
    void this.initialize();
  }

  protected isCurrentUser(member: WorkspaceMember): boolean {
    const userId = this.auth.user()?.id;

    return userId !== undefined && userId !== null && String(userId) === String(member.user_id);
  }

  protected roleLabel(role: string): string {
    return roleLabel(role);
  }

  protected statusLabel(status: string): string {
    return statusLabel(status);
  }

  protected inviteFieldError(field: string): string | null {
    return this.inviteFieldErrors()[field] ?? null;
  }

  protected async submitInvite(): Promise<void> {
    if (this.inviting()) {
      return;
    }

    this.inviteError.set(null);
    this.inviteFieldErrors.set({});
    this.copyMessage.set(null);

    const raw = this.inviteForm.getRawValue();
    const email = raw.email.trim();
    const role = raw.role;
    const clientErrors: Record<string, string> = {};

    if (email === '') {
      clientErrors['email'] = 'กรุณากรอกอีเมลของผู้รับ';
    } else if (!isValidEmail(email)) {
      clientErrors['email'] = 'กรุณากรอกอีเมลให้ถูกต้อง';
    }

    if (!INVITABLE_ROLES.includes(role)) {
      clientErrors['role'] = 'กรุณาเลือกบทบาทที่ได้รับอนุญาต';
    }

    if (Object.keys(clientErrors).length > 0) {
      this.inviteFieldErrors.set(clientErrors);
      this.inviteForm.markAllAsTouched();
      return;
    }

    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null) {
      this.inviteError.set('ไม่พบครอบครัวที่เลือก กรุณาลองใหม่อีกครั้ง');
      return;
    }

    this.inviting.set(true);

    try {
      const invitation = await this.family.invite(workspaceId, email, role);

      this.inviteForm.controls.email.setValue('');
      this.inviteResult.set({
        email: invitation.email === '' ? email : invitation.email,
        role: invitation.role === 'unknown' ? role : invitation.role,
        // The component holds the only copy while this page is open. It is
        // never written to localStorage, sessionStorage or the service.
        link: buildInviteLink(window.location.origin, invitation.token),
      });
    } catch (error) {
      const serverErrors = readFieldErrors(error);

      if (Object.keys(serverErrors).length > 0) {
        this.inviteFieldErrors.set(serverErrors);
      } else {
        this.inviteError.set(toUserMessage(error));
      }

      if (httpStatus(error) === 422) {
        this.inviteForm.markAllAsTouched();
      }
    } finally {
      this.inviting.set(false);
    }
  }

  protected async copyInviteLink(): Promise<void> {
    const result = this.inviteResult();

    if (result === null) {
      return;
    }

    const clipboard = navigator.clipboard as Clipboard | undefined;

    try {
      if (clipboard === undefined || typeof clipboard.writeText !== 'function') {
        throw new Error('clipboard_unavailable');
      }

      await clipboard.writeText(result.link);
      this.copyMessage.set('คัดลอกลิงก์แล้ว');
    } catch {
      this.copyMessage.set('คัดลอกอัตโนมัติไม่สำเร็จ กรุณาคัดลอกลิงก์ด้วยตนเอง');
    }
  }

  private resetInviteState(): void {
    this.inviteResult.set(null);
    this.inviteError.set(null);
    this.inviteFieldErrors.set({});
    this.copyMessage.set(null);
    this.inviteForm.reset({ email: '', role: 'caregiver' });
  }
}
