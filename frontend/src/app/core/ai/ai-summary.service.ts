import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { WorkspaceService } from '../workspace/workspace.service';
import { AiSummary, parseAiSummary } from './ai-summary.models';

interface EntityEnvelope {
  readonly data?: unknown;
}

/**
 * Client for `POST .../ai/summaries` (Phase 3 / G4-UI).
 *
 * It only calls the endpoint; it does not persist anything and never sends
 * identifiers the server could trust for authorization (N-01). A malformed
 * response is rejected so the UI shows an error instead of a blank summary.
 */
@Injectable({ providedIn: 'root' })
export class AiSummaryService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  async summarize(profileId: string): Promise<AiSummary> {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null) {
      throw new Error('No workspace is selected.');
    }

    const url = `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/profiles/${encodeURIComponent(profileId)}/ai/summaries`;

    const response = await firstValueFrom(this.http.post<EntityEnvelope>(url, {}));
    const summary = parseAiSummary(response?.data);

    if (summary === null) {
      throw new Error('The AI summary response was malformed.');
    }

    return summary;
  }
}
