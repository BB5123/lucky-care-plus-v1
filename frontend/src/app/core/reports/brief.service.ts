import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { API_BASE_URL } from '../api/api-base.token';
import { HealthReportPayload, parseBriefPayload } from './report.models';

/**
 * Public Doctor Brief reader (DB-003/DB-004).
 *
 * The 256-bit token is the capability; the endpoint is unauthenticated and
 * returns the frozen payload directly (no `{data}` envelope). The token is
 * never stored or logged by this client. A malformed body is rejected.
 */
@Injectable({ providedIn: 'root' })
export class BriefService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(API_BASE_URL);

  async fetch(token: string): Promise<HealthReportPayload> {
    const payload = await firstValueFrom(
      this.http.get<unknown>(`${this.baseUrl}/briefs/${encodeURIComponent(token)}`),
    );
    const parsed = parseBriefPayload(payload);

    if (parsed === null) {
      throw new Error('The brief response was malformed.');
    }

    return parsed;
  }
}
