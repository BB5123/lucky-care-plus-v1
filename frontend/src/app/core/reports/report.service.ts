import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { WorkspaceService } from '../workspace/workspace.service';
import { HealthReport, ReportShare, parseHealthReport, parseReportShare } from './report.models';

interface EntityEnvelope {
  readonly data?: unknown;
}

/**
 * Client for the Doctor Brief endpoints (Phase 2.3).
 *
 * Create and share are separate calls because they are separate permissions
 * (F-06): `health_report.create` for the snapshot, `health_report.export` plus
 * `data_export` consent for the 30-minute capability link.
 */
@Injectable({ providedIn: 'root' })
export class ReportService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly workspace = inject(WorkspaceService);

  async create(profileId: string): Promise<HealthReport> {
    const url = `${this.reportPath(profileId)}/health-reports`;
    const response = await firstValueFrom(this.http.post<EntityEnvelope>(url, {}));
    const report = parseHealthReport(response?.data);

    if (report === null) {
      throw new Error('The doctor brief response was malformed.');
    }

    return report;
  }

  async share(profileId: string, reportId: string, expiresInMinutes = 30): Promise<ReportShare> {
    const url = `${this.reportPath(profileId)}/health-reports/${encodeURIComponent(reportId)}/share`;
    const response = await firstValueFrom(
      this.http.post<EntityEnvelope>(url, { expires_in_minutes: expiresInMinutes }),
    );
    const share = parseReportShare(response?.data);

    if (share === null) {
      throw new Error('The share response was malformed.');
    }

    return share;
  }

  private reportPath(profileId: string): string {
    const workspaceId = this.workspace.currentWorkspace()?.workspace_id ?? null;

    if (workspaceId === null) {
      throw new Error('No workspace is selected.');
    }

    return `${this.baseUrl}/workspaces/${encodeURIComponent(
      workspaceId,
    )}/profiles/${encodeURIComponent(profileId)}`;
  }
}
