import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { WorkspaceService } from '../workspace/workspace.service';
import { KnowledgeAnswer, parseKnowledgeAnswer } from './knowledge.models';

interface EntityEnvelope {
  readonly data?: unknown;
}

/**
 * Client for `POST .../ai/knowledge-answers` (TASK G4-KB).
 *
 * The question is general health information, not a patient record; the
 * server still applies consent, quota, intake refusal and corpus-only
 * answering. A malformed envelope is rejected instead of rendering blanks.
 */
@Injectable({ providedIn: 'root' })
export class KnowledgeService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  async ask(profileId: string, question: string): Promise<KnowledgeAnswer> {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null) {
      throw new Error('No workspace is selected.');
    }

    const url = `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/profiles/${encodeURIComponent(profileId)}/ai/knowledge-answers`;

    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(url, { question: question.trim() }),
    );
    const answer = parseKnowledgeAnswer(response?.data);

    if (answer === null) {
      throw new Error('The knowledge answer response was malformed.');
    }

    return answer;
  }
}
