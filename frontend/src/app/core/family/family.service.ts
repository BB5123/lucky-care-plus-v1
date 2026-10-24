import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { httpStatus, toUserMessage } from '../api/problem-details';
import {
  INVITABLE_ROLES,
  InvitableRole,
  InvitationResult,
  WorkspaceMember,
  parseInvitation,
  parseMembers,
} from './member.models';

interface CollectionEnvelope {
  readonly data?: unknown;
}

interface EntityEnvelope {
  readonly data?: unknown;
}

/**
 * Family membership reads and invitations.
 *
 * `loadMembers` keeps the usual loading/error/errorStatus trio so a 403 can be
 * rendered as the expected "no permission" state instead of a failure. The
 * invitation token exists only in the returned value: it is deliberately not
 * copied into a service signal, so a later screen cannot re-read the secret.
 */
@Injectable({ providedIn: 'root' })
export class FamilyService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  private readonly membersState = signal<readonly WorkspaceMember[]>([]);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private readonly errorStatusState = signal<number | null>(null);

  readonly members = this.membersState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();
  readonly errorStatus = this.errorStatusState.asReadonly();

  private listToken = 0;

  reset(): void {
    this.listToken += 1;
    this.membersState.set([]);
    this.loadingState.set(false);
    this.errorState.set(null);
    this.errorStatusState.set(null);
  }

  async loadMembers(workspaceId: string): Promise<void> {
    if (workspaceId === '') {
      this.reset();
      return;
    }

    const token = ++this.listToken;

    this.loadingState.set(true);
    this.errorState.set(null);
    this.errorStatusState.set(null);

    try {
      const response = await firstValueFrom(
        this.http.get<CollectionEnvelope>(
          `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/members`,
        ),
      );

      if (token !== this.listToken) {
        return;
      }

      this.membersState.set(parseMembers(response.data));
    } catch (error) {
      if (token !== this.listToken) {
        return;
      }

      // A denied read is an expected viewer/caregiver state. The page uses the
      // status to explain it; the empty list never leaks a partial result.
      this.membersState.set([]);
      this.errorStatusState.set(httpStatus(error));
      this.errorState.set(toUserMessage(error));
    } finally {
      if (token === this.listToken) {
        this.loadingState.set(false);
      }
    }
  }

  /**
   * Issues an invitation. The returned `token` is the only copy of the secret
   * and is never stored on this service.
   */
  async invite(workspaceId: string, email: string, role: InvitableRole): Promise<InvitationResult> {
    if (workspaceId === '') {
      throw new Error('workspace_missing');
    }

    if (!INVITABLE_ROLES.includes(role)) {
      throw new Error('role_not_invitable');
    }

    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(
        `${this.baseUrl}/workspaces/${encodeURIComponent(workspaceId)}/invitations`,
        { email, role },
      ),
    );
    const invitation = parseInvitation(response.data);

    if (invitation === null) {
      throw new Error('invalid_invitation_payload');
    }

    return invitation;
  }
}
